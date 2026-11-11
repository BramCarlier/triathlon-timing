import { tr } from '../i18n';
import { computed, ref, onBeforeUnmount } from 'vue';
import { jsonRequest } from '../lib';
import { mayReplay, retryDisposition, type QueueOwner } from '../queuePolicy';

/** A tap saved on this device until the server confirms it. */
export interface QueuedTiming {
  client_uuid: string;
  url: string;
  payload: Record<string, unknown>;
  queued_at: string;
  operator_id?: QueueOwner;
  label?: string;
  elapsed_ms?: number;
  error?: string;
  blocked?: boolean;
  warning?: boolean;
}

const STORE = 'timings';

async function db(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open('triathlon-timing', 1);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE, { keyPath: 'client_uuid' });
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function transaction<T>(mode: IDBTransactionMode, action: (store: IDBObjectStore) => IDBRequest<T>): Promise<T> {
  const database = await db();
  return new Promise((resolve, reject) => {
    const tx = database.transaction(STORE, mode);
    const request = action(tx.objectStore(STORE));
    tx.oncomplete = () => { database.close(); resolve(request.result); };
    tx.onabort = tx.onerror = () => { database.close(); reject(tx.error ?? request.error); };
  });
}

const all = () => transaction<QueuedTiming[]>('readonly', store => store.getAll());
const put = (item: QueuedTiming) => transaction('readwrite', store => store.put(item));
const remove = (id: string) => transaction('readwrite', store => store.delete(id));
const byQueuedAt = (a: QueuedTiming, b: QueuedTiming) => a.queued_at.localeCompare(b.queued_at);

/**
 * Taps that could not reach the server, kept in IndexedDB and replayed in order.
 * Each entry keeps its client UUID, so a replay after a lost response is safe.
 *
 * @param url   the endpoint the taps belong to (scopes the queue to one race)
 * @param owner the account id, or a link scope; taps are only replayed by their owner
 */
export function useOfflineTimingQueue(url: string, owner: QueueOwner) {
  let disposed = false;
  onBeforeUnmount(() => { disposed = true; });
  const items = ref<QueuedTiming[]>([]);
  const foreignCount = ref(0);
  const legacy = ref<QueuedTiming[]>([]);
  const error = ref('');
  const flushing = ref(false);
  const pending = computed(() => items.value.length);

  const scoped = async () => (await all()).filter(item => item.url === url).sort(byQueuedAt);

  const refresh = async () => {
    try {
      const rows = await scoped();
      items.value = rows.filter(item => mayReplay(item.operator_id, owner));
      foreignCount.value = rows.filter(item => item.operator_id !== undefined && !mayReplay(item.operator_id, owner)).length;
      legacy.value = rows.filter(item => item.operator_id === undefined);
      error.value = '';
    } catch {
      error.value = tr('Device storage is unavailable. Keep this page open; offline timing cannot be saved safely.');
      throw new Error(error.value);
    }
  };

  const queue = async (payload: Record<string, unknown>, label: string, elapsedMs: number) => {
    const body = { ...payload, source: 'offline', ...(typeof owner === 'number' ? { operator_id: owner } : {}) };
    await put({ client_uuid: String(payload.client_uuid), url, payload: body, operator_id: owner, label, elapsed_ms: elapsedMs, queued_at: new Date().toISOString() });
    await refresh();
  };

  const flush = async () => {
    if (disposed || !navigator.onLine || flushing.value) return false;
    flushing.value = true;
    let changed = false;
    try {
      const run = async () => {
        for (const item of await scoped()) {
          if (disposed) break;
          // Old versions blocked skipped checkpoints as warnings. Retry those normally.
          if (!mayReplay(item.operator_id, owner) || (item.blocked && !item.warning)) continue;
          try {
            const { response, data } = await jsonRequest<{ message?: string; warning?: boolean; timing?: { client_uuid?: string } }>(item.url, {
              method: 'POST', body: JSON.stringify(item.payload), signal: AbortSignal.timeout(12000),
            });
            if (response.ok && data.timing?.client_uuid === item.client_uuid) {
              await remove(item.client_uuid);
              changed = true;
            } else {
              await put({ ...item, error: data.message ?? tr('Server response :status', { status: response.status }), blocked: retryDisposition(response.status) === 'review', warning: !!data.warning });
              if ([401, 403, 419, 429].includes(response.status) || response.status >= 500) break;
            }
          } catch {
            await put({ ...item, error: tr('Connection interrupted. Will retry automatically.') });
            break;
          }
        }
      };
      const lockName = `timing-sync-${url}-${owner}`;
      if (navigator.locks) await navigator.locks.request(lockName, run);
      else await run();
      await refresh();
      return changed;
    } finally {
      flushing.value = false;
    }
  };

  const retry = async (id: string) => {
    const item = (await scoped()).find(i => i.client_uuid === id && mayReplay(i.operator_id, owner));
    if (item) await put({ ...item, blocked: false, error: undefined });
    return flush();
  };

  const discard = async (id: string) => {
    const item = (await scoped()).find(i => i.client_uuid === id && mayReplay(i.operator_id, owner));
    if (item) await remove(id);
    await refresh();
  };

  return { items, pending, foreignCount, legacy, error, flushing, queue, flush, refresh, retry, discard };
}
