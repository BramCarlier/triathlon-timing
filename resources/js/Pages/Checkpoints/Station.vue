<script setup lang="ts">
import { loadEcho } from '../../echo';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import Icon from '../../Components/Icon.vue';
import TimingPad from '../../Components/TimingPad.vue';
import LiveUpdatesStatus from '../../Components/LiveUpdatesStatus.vue';
import { useWakeLock } from '../../Composables/useWakeLock';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { useTimingRecorder, type RecordedTap } from '../../Composables/useTimingRecorder';
import { checkpointDistanceText } from '../../checkpointDistance';
import { bibLabel, deviceId, formatDuration, jsonRequest } from '../../lib';
import { tr } from '../../i18n';
import type { Checkpoint, PageProps, Race, StationParticipant } from '../../types';

const props = defineProps<{
  race: Race;
  checkpoint?: Checkpoint | null;
  checkpoints: Checkpoint[];
  recentTimings: RecordedTap[];
  participants: StationParticipant[];
  serverNow: string;
}>();

const account = usePage<PageProps>().props.auth.user!;
const isAdmin = account.role === 'admin';
const selectForm = useForm<{ checkpoint_id: number | null }>({ checkpoint_id: props.checkpoint?.id ?? props.checkpoints[0]?.id ?? null });
watch(() => props.checkpoint?.id, id => { selectForm.checkpoint_id = id ?? null; });
const selectCheckpoint = () => selectForm.post(`/races/${props.race.id}/checkpoint-selection`, { preserveScroll: true });

const roster = ref<StationParticipant[]>([]);
const copyParticipants = (rows: StationParticipant[]) => rows.map(p => ({ ...p, completed_checkpoint_ids: [...p.completed_checkpoint_ids] }));
roster.value = copyParticipants(props.participants);
watch(() => props.participants, rows => { roster.value = copyParticipants(rows); });

const recorder = useTimingRecorder({
  url: `/races/${props.race.id}/timings`,
  owner: account.id,
  race: () => props.race,
  checkpoint: () => props.checkpoint ?? null,
  participants: roster,
  extraPayload: () => ({ operator_id: account.id }),
  undoRemote: async tap => {
    if (!tap.id) return tr('Reconnect before undoing a synced timing.');
    const { response, data } = await jsonRequest<{ message?: string }>(`/races/${props.race.id}/timings/${tap.id}/void`, { method: 'POST', body: JSON.stringify({ reason: tr('Accidental checkpoint tap') }) });
    return response.ok ? null : (data.message ?? tr('Timing could not be removed.'));
  },
  onSynced: () => refreshRace(),
});

const refreshRace = useRaceRefresh(() => ['race', 'serverNow', ...(recorder.pending.value === 0 && recorder.saving.value.size === 0 ? ['participants', 'recentTimings'] : [])]);

// Taps from this session first, then what the server reports for this account.
const recent = computed(() => {
  const local = recorder.recent.value.filter(tap => tap.checkpoint?.id === props.checkpoint?.id);
  const uuids = new Set(local.map(tap => tap.client_uuid));
  return [...local, ...props.recentTimings.filter(tap => !uuids.has(tap.client_uuid))].slice(0, 10);
});

const wake = useWakeLock();
const { queue } = recorder;
const recoveryNeedsAttention = computed(() => !!queue.error.value || queue.foreignCount.value > 0 || queue.legacy.value.length > 0 || recorder.pending.value > 0);
const confirmation = ref<{ message: string; label: string; action: () => void | Promise<void> } | null>(null);
const confirmAction = async () => { const action = confirmation.value?.action; confirmation.value = null; await action?.(); };
const retryPending = async (id: string) => { try { await queue.retry(id); refreshRace(); } catch { recorder.show({ type: 'error', message: tr('Unable to access pending timings.') }); } };
const removePending = (id: string) => {
  confirmation.value = { message: tr('Discard this unsynchronized timing? Export a backup first if it may be needed.'), label: tr('Discard timing'), action: async () => { await queue.discard(id); refreshRace(); } };
};
const exportPending = () => {
  const rows = [...queue.items.value, ...(isAdmin ? queue.legacy.value : [])];
  const url = URL.createObjectURL(new Blob([JSON.stringify(rows, null, 2)], { type: 'application/json' }));
  const link = document.createElement('a');
  link.href = url;
  link.download = `race-${props.race.id}-pending-timings.json`;
  link.click();
  window.setTimeout(() => URL.revokeObjectURL(url), 1000);
};

async function ping() {
  if (!props.checkpoint || !recorder.online.value) return;
  try {
    const { response, data } = await jsonRequest<{ started_at?: string | null; finished_at?: string | null }>(`/races/${props.race.id}/presence`, {
      method: 'POST', body: JSON.stringify({ device_uuid: deviceId(), checkpoint_id: props.checkpoint.id, pending_count: recorder.pending.value }),
    });
    if (response.ok && ((data.started_at ?? null) !== (props.race.started_at ?? null) || (data.finished_at ?? null) !== (props.race.finished_at ?? null))) refreshRace();
  } catch { /* presence is best-effort */ }
}

let pingTimer: number | undefined;
const channelName = `race.${props.race.id}`;
onMounted(async () => {
  const echo = await loadEcho();
  if (echo) {
    echo.private(channelName)
      .listen('.race.started', () => router.reload({ only: ['race', 'serverNow'] }))
      .listen('.race.finished', () => router.reload({ only: ['race', 'serverNow', 'participants', 'recentTimings'] }))
      .listen('.timing.recorded', (event: { entry_id: number; checkpoint_id: number }) => {
        const p = roster.value.find(item => item.id === event.entry_id);
        if (p && !p.completed_checkpoint_ids.includes(event.checkpoint_id)) p.completed_checkpoint_ids.push(event.checkpoint_id);
      })
      .listen('.timing.voided', (event: { entry_id: number; checkpoint_id: number }) => {
        const p = roster.value.find(item => item.id === event.entry_id);
        if (p) p.completed_checkpoint_ids = p.completed_checkpoint_ids.filter(id => id !== event.checkpoint_id);
      });
  }
  pingTimer = window.setInterval(ping, 30000);
  void ping();
});
onBeforeUnmount(() => {
  window.clearInterval(pingTimer);
  (window as any).Echo?.leave(channelName);
});
</script>

<template>
  <Head :title="`${race.name} · ${$t('Timing station')}`"/>
  <AppLayout :title="race.name">
    <section v-if="!checkpoint" class="mx-auto max-w-2xl panel-pad">
      <template v-if="isAdmin">
        <h2 class="text-2xl">{{ $t("Choose a checkpoint") }}</h2>
        <p class="mt-2 muted">{{ $t("As Organizer you can move between checkpoints when needed.") }}</p>
        <form class="mt-5 grid gap-3 sm:grid-cols-[1fr_auto] sm:items-end" @submit.prevent="selectCheckpoint">
          <label class="label !mb-0">{{ $t("Checkpoint") }}
            <select v-model="selectForm.checkpoint_id" class="field mt-1.5">
              <option v-for="cp in checkpoints" :key="cp.id" :value="cp.id">{{ $t(cp.name) }} · {{ checkpointDistanceText(race, cp) }}</option>
            </select>
          </label>
          <button class="btn-primary" :disabled="selectForm.processing"><Icon name="play"/>{{ $t("Open checkpoint") }}</button>
        </form>
      </template>
      <template v-else>
        <h2 class="text-2xl">{{ $t("No checkpoint assigned") }}</h2>
        <p class="mt-2 muted">{{ $t("Ask the Organizer to assign you to a checkpoint before race day. Officials cannot choose or switch checkpoints themselves.") }}</p>
      </template>
    </section>

    <template v-else>
      <p v-if="race.finished_at" class="mb-4 rounded-lg border border-success/40 bg-success/10 p-4 text-success">
        {{ $t("Race finished. The shared clock is stopped.") }}
        <Link :href="`/races/${race.id}/results`" class="font-bold underline">{{ $t("View results") }}</Link>
        <Link v-if="isAdmin" :href="`/races/${race.id}/control`" class="ml-2 underline">{{ $t("Correct a timing") }}</Link>
      </p>

      <div class="mb-3 flex flex-wrap items-center justify-between gap-3">
        <div class="flex flex-wrap items-center gap-3 text-sm">
          <span class="inline-flex items-center gap-1.5 font-semibold" :class="recorder.online.value ? 'text-success' : 'text-warning'">
            <Icon :name="recorder.online.value ? 'wifi' : 'wifi-off'"/>{{ recorder.online.value ? $t('Online') : $t('Offline') }}
          </span>
          <LiveUpdatesStatus :race-id="race.id"/>
          <span class="muted">{{ checkpointDistanceText(race, checkpoint) }}</span>
        </div>
        <form v-if="isAdmin && checkpoints.length > 1" class="flex items-center gap-2" @submit.prevent="selectCheckpoint">
          <label class="sr-only" for="station-checkpoint">{{ $t("Checkpoint") }}</label>
          <select id="station-checkpoint" v-model="selectForm.checkpoint_id" class="field !min-h-11 !py-1.5" @change="selectCheckpoint">
            <option v-for="cp in checkpoints" :key="cp.id" :value="cp.id">{{ $t(cp.name) }}</option>
          </select>
        </form>
      </div>

      <div class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_360px] xl:items-start">
        <TimingPad
          :race="race"
          :server-now="serverNow"
          :checkpoints="checkpoints"
          :checkpoint-id="checkpoint.id"
          :participants="roster"
          :recorder="recorder"
          locked
          storage-key="tt.keypad.station"
        />

        <div class="grid gap-5">
          <section class="panel overflow-hidden">
            <div class="flex items-center justify-between gap-3 border-b border-outline px-4 py-3">
              <h2 class="text-xl">{{ $t("Your recent taps") }}</h2>
              <span class="text-sm muted">{{ $t("Undo mistakes here") }}</span>
            </div>
            <ol v-if="recent.length" class="divide-y divide-outline">
              <li v-for="tap in recent" :key="tap.client_uuid || tap.id" class="flex items-center justify-between gap-3 px-4 py-3">
                <div class="min-w-0">
                  <strong class="block truncate">{{ tap.entry?.display_name }} <span class="font-normal muted">{{ bibLabel(tap.entry?.bib_number) }}</span></strong>
                  <span class="tabular text-sm" :class="tap.queued ? 'text-warning' : 'muted'">{{ formatDuration(tap.elapsed_ms, 2) }}<template v-if="tap.queued"> · {{ $t('Waiting to upload') }}</template></span>
                </div>
                <button v-if="!race.finished_at" type="button" class="btn-quiet !min-h-10 text-sm" @click="recorder.undo(tap)"><Icon name="undo"/>{{ $t("Undo") }}</button>
              </li>
            </ol>
            <p v-else class="p-6 text-center muted">{{ $t("No timings recorded by you here yet.") }}</p>
          </section>

          <details class="panel-pad" :open="recoveryNeedsAttention" :aria-label="$t('Device and sync')">
            <summary class="flex items-center justify-between gap-3 font-semibold">
              <span class="inline-flex items-center gap-2"><Icon name="cloud-arrow-up"/>{{ $t("Device & sync") }}</span>
              <span :class="recoveryNeedsAttention ? 'text-warning' : 'text-success'" class="text-sm">{{ recoveryNeedsAttention ? (recorder.pending.value ? $t(':count waiting to upload', { count: recorder.pending.value }) : $t('Needs attention')) : $t('Everything saved') }}</span>
            </summary>
            <div class="mt-4 border-t border-outline pt-4">
              <p v-if="queue.error.value" role="alert" class="text-error">{{ queue.error.value }}</p>
              <p v-if="queue.foreignCount.value" class="text-warning">{{ queue.foreignCount.value }} {{ $t("pending timings belong to another account. Sign in with that account to synchronize them.") }}</p>
              <p v-if="queue.legacy.value.length" class="text-warning">{{ queue.legacy.value.length }} {{ $t("older timings have no recorded account owner. An Organizer can export and review them in Corrections & station health.") }}</p>
              <p class="mb-3 text-sm muted">{{ recorder.pending.value ? $t('These timings are safely stored on this device until they can upload.') : $t('Offline recovery is ready. You normally do not need anything here.') }}</p>
              <div class="flex flex-wrap items-center gap-2">
                <button v-if="recorder.pending.value" class="btn-secondary" :disabled="queue.flushing.value || !recorder.online.value" @click="recorder.sync()"><Icon name="cloud-arrow-up"/>{{ queue.flushing.value ? $t('Uploading…') : $t('Upload now') }}</button>
                <button v-if="recorder.pending.value || (isAdmin && queue.legacy.value.length)" class="btn-secondary" @click="exportPending"><Icon name="file-export"/>{{ $t("Export backup") }}</button>
                <button v-if="wake.supported" class="btn-secondary" @click="wake.toggle"><Icon :name="wake.enabled.value ? 'moon' : 'eye'"/>{{ wake.enabled.value ? $t('Allow screen sleep') : $t('Keep screen awake') }}</button>
                <span v-if="wake.active.value" class="text-sm text-success">{{ $t("Screen stays awake") }}</span>
                <span v-if="wake.error.value" class="text-sm text-warning">{{ wake.error.value }}</span>
              </div>
              <div v-for="item in queue.items.value" :key="item.client_uuid" class="mt-3 rounded-md border border-warning/50 p-3">
                <strong>{{ item.label ?? $t('Pending participant') }}</strong> · <span class="tabular">{{ formatDuration(item.elapsed_ms, 2) }}</span> · {{ $t(checkpoints.find(c => c.id === item.payload.checkpoint_id)?.name ?? '') }}
                <p class="text-sm text-warning">{{ item.error ?? $t('Saved on this device. Waiting to synchronize.') }}</p>
                <div class="mt-2 flex flex-wrap gap-2">
                  <button class="btn-secondary" :disabled="queue.flushing.value || !recorder.online.value" @click="retryPending(item.client_uuid)"><Icon name="rotate"/>{{ $t("Retry") }}</button>
                  <button class="btn-danger" :disabled="queue.flushing.value" @click="removePending(item.client_uuid)"><Icon name="trash"/>{{ $t("Discard") }}</button>
                </div>
              </div>
              <p class="mt-4 text-sm muted">{{ $t("For unreliable connections, open this timing station before moving to the checkpoint. Unsent timings are stored on this device and synchronize automatically.") }}</p>
            </div>
          </details>
        </div>
      </div>
    </template>
    <ConfirmDialog v-if="confirmation" :title="$t('Confirm timing action')" :message="confirmation.message" :confirm-label="confirmation.label" @cancel="confirmation = null" @confirm="confirmAction"/>
  </AppLayout>
</template>
