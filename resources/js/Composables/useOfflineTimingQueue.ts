import { ref } from 'vue';
import { jsonRequest } from '../lib';

export interface QueuedTiming {
  client_uuid: string;
  url: string;
  payload: Record<string, unknown>;
  queued_at: string;
}

const DB_NAME = 'triathlon-timing';
const STORE = 'timings';

function openDb(): Promise<IDBDatabase> {
  return new Promise((resolve, reject) => {
    const request = indexedDB.open(DB_NAME, 1);
    request.onupgradeneeded = () => {
      if (!request.result.objectStoreNames.contains(STORE)) {
        request.result.createObjectStore(STORE, { keyPath: 'client_uuid' });
      }
    };
    request.onsuccess = () => resolve(request.result);
    request.onerror = () => reject(request.error);
  });
}

async function all(): Promise<QueuedTiming[]> {
  const db = await openDb();

  return new Promise((resolve, reject) => {
    const request = db.transaction(STORE, 'readonly').objectStore(STORE).getAll();
    request.onsuccess = () => resolve(request.result as QueuedTiming[]);
    request.onerror = () => reject(request.error);
  });
}

async function put(item: QueuedTiming): Promise<void> {
  const db = await openDb();
  await new Promise<void>((resolve, reject) => {
    const request = db.transaction(STORE, 'readwrite').objectStore(STORE).put(item);
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error);
  });
}

async function remove(id: string): Promise<void> {
  const db = await openDb();
  await new Promise<void>((resolve, reject) => {
    const request = db.transaction(STORE, 'readwrite').objectStore(STORE).delete(id);
    request.onsuccess = () => resolve();
    request.onerror = () => reject(request.error);
  });
}

export function useOfflineTimingQueue(raceId: number) {
  const pending = ref(0);
  const prefix = `/races/${raceId}/`;
  const scoped = async () => (await all()).filter((item) => item.url.startsWith(prefix));
  const refresh = async () => { pending.value = (await scoped()).length; };

  const queue = async (url: string, payload: Record<string, unknown>) => {
    await put({
      client_uuid: String(payload.client_uuid),
      url,
      payload,
      queued_at: new Date().toISOString(),
    });
    await refresh();
  };

  const flush = async () => {
    if (!navigator.onLine) return;

    for (const item of await scoped()) {
      try {
        const { response, data } = await jsonRequest<Record<string, unknown>>(item.url, {
          method: 'POST',
          body: JSON.stringify(item.payload),
        });
        if (response.ok || (response.status === 409 && String(data.message ?? '').includes('already recorded'))) {
          await remove(item.client_uuid);
        }
      } catch {
        break;
      }
    }

    await refresh();
  };

  const discard = async (id: string) => {
    await remove(id);
    await refresh();
  };

  void refresh();

  return { pending, queue, flush, refresh, discard };
}
