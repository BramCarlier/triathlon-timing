import { computed, onBeforeUnmount, onMounted, ref, type Ref } from 'vue';
import { tr } from '../i18n';
import { jsonRequest, uuid } from '../lib';
import { serverNowMs } from '../timing/serverClock';
import { parseRaceTimestamp } from '../raceTime';
import { useOfflineTimingQueue } from './useOfflineTimingQueue';
import type { QueueOwner } from '../queuePolicy';
import type { Checkpoint, StationParticipant } from '../types';

export interface RecordedTap {
  id?: number;
  client_uuid: string;
  elapsed_ms: number;
  recorded_at: string;
  entry?: { id: number; bib_number: string | null; display_name?: string };
  checkpoint?: { id?: number; name: string };
  operator?: string | null;
  queued?: boolean;
  undo_until?: string | null;
}

export interface TimingFeedback {
  type: 'ok' | 'error' | 'offline';
  message: string;
  tap?: RecordedTap;
}

interface RaceState { started_at?: string | null; finished_at?: string | null }

export interface TimingRecorderOptions {
  /** POST endpoint for taps. */
  url: string;
  /** Who may replay taps saved on this device: the account id or a link scope. */
  owner: QueueOwner;
  race: () => RaceState;
  checkpoint: () => Checkpoint | null;
  participants: Ref<StationParticipant[]>;
  /** Extra fields sent with every tap (operator id, workspace flag). */
  extraPayload?: () => Record<string, unknown>;
  /** Undo a tap that reached the server. Returns an error message or null. */
  undoRemote: (tap: RecordedTap) => Promise<string | null>;
  /** Called after a tap is stored on the server or the device. */
  onRecorded?: (tap: RecordedTap, info: { autoFinished: boolean; queued: boolean }) => void;
  /** Called after queued taps uploaded. */
  onSynced?: () => void;
}

/**
 * The single implementation of "tap an athlete" used by the race-day link,
 * the Organizer workspace and the authenticated timing station.
 *
 * - Every tap is stamped on the server clock (see serverClock.ts).
 * - A tap that cannot reach the server is saved on the device with its
 *   original time and uploaded later; the client UUID makes that idempotent.
 * - The latest tap can be undone.
 */
export function useTimingRecorder(options: TimingRecorderOptions) {
  const queue = useOfflineTimingQueue(options.url, options.owner);
  const online = ref(typeof navigator === 'undefined' ? true : navigator.onLine);
  const saving = ref(new Set<number>());
  const feedback = ref<TimingFeedback | null>(null);
  const recent = ref<RecordedTap[]>([]);
  let feedbackTimer: number | undefined;

  const show = (next: TimingFeedback, durationMs = 6000) => {
    feedback.value = next;
    window.clearTimeout(feedbackTimer);
    feedbackTimer = window.setTimeout(() => { if (feedback.value === next) feedback.value = null; }, durationMs);
  };
  const dismiss = () => { feedback.value = null; };

  const isQueued = (participant: StationParticipant, checkpointId: number) => queue.items.value.some(item =>
    Number(item.payload.entry_id) === participant.id && Number(item.payload.checkpoint_id) === checkpointId);

  const isRecorded = (participant: StationParticipant, checkpointId = options.checkpoint()?.id) => checkpointId !== undefined
    && (participant.completed_checkpoint_ids.includes(checkpointId) || isQueued(participant, checkpointId));

  const markRecorded = (participantId: number, checkpointId: number, recorded: boolean) => {
    const participant = options.participants.value.find(item => item.id === participantId);
    if (!participant) return;
    const ids = participant.completed_checkpoint_ids.filter(id => id !== checkpointId);
    participant.completed_checkpoint_ids = recorded ? [...ids, checkpointId] : ids;
  };

  const vibrate = (pattern: number | number[]) => { try { navigator.vibrate?.(pattern); } catch { /* unsupported */ } };

  async function record(participant: StationParticipant): Promise<void> {
    const checkpoint = options.checkpoint();
    const race = options.race();
    if (!checkpoint || saving.value.has(participant.id)) return;
    if (!race.started_at) { show({ type: 'error', message: tr('The race has not started yet.') }); return; }
    if (race.finished_at) { show({ type: 'error', message: tr('The race is finished.') }); return; }
    if (participant.status && participant.status !== 'registered') {
      show({ type: 'error', message: tr(':name is marked :status.', { name: participant.name, status: participant.status.toUpperCase() }) });
      return;
    }
    if (isRecorded(participant, checkpoint.id)) {
      show({ type: 'error', message: tr(':name already has a time at this checkpoint.', { name: participant.name }) });
      return;
    }

    const observedMs = serverNowMs();
    const startedMs = parseRaceTimestamp(race.started_at);
    const tap: RecordedTap = {
      client_uuid: uuid(),
      elapsed_ms: Math.max(0, observedMs - startedMs),
      recorded_at: new Date(observedMs).toISOString(),
      entry: { id: participant.id, bib_number: participant.bib_number, display_name: participant.name },
      checkpoint: { id: checkpoint.id, name: checkpoint.name },
    };
    const payload = {
      entry_id: participant.id,
      checkpoint_id: checkpoint.id,
      client_uuid: tap.client_uuid,
      observed_at: tap.recorded_at,
      source: 'online',
      ...(options.extraPayload?.() ?? {}),
    };

    const saveOnDevice = async () => {
      await queue.queue(payload, participant.name, tap.elapsed_ms);
      const queued = { ...tap, queued: true };
      recent.value = [queued, ...recent.value].slice(0, 12);
      vibrate([30, 60, 30]);
      show({ type: 'offline', message: tr(':name saved on this device. It uploads automatically.', { name: participant.name }), tap: queued }, 8000);
      options.onRecorded?.(queued, { autoFinished: false, queued: true });
    };

    saving.value.add(participant.id);
    try {
      if (!online.value) { await saveOnDevice(); return; }
      let result;
      try {
        result = await jsonRequest<{ timing?: RecordedTap; message?: string; auto_finished?: boolean; undo_until?: string }>(options.url, {
          method: 'POST', body: JSON.stringify(payload), signal: AbortSignal.timeout(12000),
        });
      } catch {
        await saveOnDevice();
        return;
      }
      const { response, data } = result;
      if (response.ok && data.timing) {
        markRecorded(participant.id, checkpoint.id, true);
        const saved: RecordedTap = { ...tap, ...data.timing, client_uuid: tap.client_uuid, undo_until: data.undo_until ?? null, entry: tap.entry, checkpoint: tap.checkpoint };
        recent.value = [saved, ...recent.value].slice(0, 12);
        vibrate(35);
        show({ type: 'ok', message: data.message ?? tr('Time recorded.'), tap: saved }, 8000);
        options.onRecorded?.(saved, { autoFinished: !!data.auto_finished, queued: false });
        return;
      }
      if ((response.ok && !data.timing) || response.status >= 500 || [401, 408, 419, 429].includes(response.status)) {
        await saveOnDevice();
        return;
      }
      vibrate([80, 40, 80]);
      show({ type: 'error', message: data.message ?? tr('The time was not recorded. Try again.') }, 9000);
    } catch {
      show({ type: 'error', message: tr('The time was NOT saved and device storage is unavailable. Write it down and tell the Organizer.') }, 15000);
    } finally {
      saving.value.delete(participant.id);
    }
  }

  async function undo(tap: RecordedTap): Promise<void> {
    const checkpointId = tap.checkpoint?.id;
    if (tap.queued) {
      await queue.discard(tap.client_uuid);
    } else {
      if (!online.value) { show({ type: 'error', message: tr('Reconnect before undoing a synced timing.') }); return; }
      const error = await options.undoRemote(tap);
      if (error) { show({ type: 'error', message: error }, 9000); return; }
    }
    if (tap.entry && checkpointId) markRecorded(tap.entry.id, checkpointId, false);
    recent.value = recent.value.filter(item => item.client_uuid !== tap.client_uuid);
    show({ type: 'ok', message: tr(':name removed from :checkpoint.', { name: tap.entry?.display_name ?? '', checkpoint: tr(tap.checkpoint?.name ?? '') }) }, 4000);
  }

  const sync = async () => {
    try {
      if (await queue.flush()) options.onSynced?.();
    } catch {
      show({ type: 'error', message: tr('Unable to access pending timings. Keep this page open.') });
    }
  };

  let syncTimer: number | undefined;
  const handleOnline = () => { online.value = true; void sync(); };
  const handleOffline = () => { online.value = false; };
  const handleVisible = () => { if (document.visibilityState === 'visible') void sync(); };

  onMounted(async () => {
    window.addEventListener('online', handleOnline);
    window.addEventListener('offline', handleOffline);
    document.addEventListener('visibilitychange', handleVisible);
    syncTimer = window.setInterval(() => void sync(), 15000);
    try { await queue.refresh(); await sync(); } catch { /* error is exposed through queue.error */ }
  });
  onBeforeUnmount(() => {
    window.removeEventListener('online', handleOnline);
    window.removeEventListener('offline', handleOffline);
    document.removeEventListener('visibilitychange', handleVisible);
    window.clearInterval(syncTimer);
    window.clearTimeout(feedbackTimer);
  });

  return {
    queue,
    online,
    saving,
    feedback,
    recent,
    pending: computed(() => queue.pending.value),
    isRecorded,
    record,
    undo,
    sync,
    show,
    dismiss,
  };
}

export type TimingRecorder = ReturnType<typeof useTimingRecorder>;
