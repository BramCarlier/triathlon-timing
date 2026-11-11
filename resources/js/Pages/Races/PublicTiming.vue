<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import TimingPad from '../../Components/TimingPad.vue';
import LiveStandings from '../../Components/LiveStandings.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { useTimingRecorder, type RecordedTap } from '../../Composables/useTimingRecorder';
import { useWakeLock } from '../../Composables/useWakeLock';
import { bibLabel, formatDuration, jsonRequest } from '../../lib';
import { tr } from '../../i18n';
import type { Checkpoint, LiveStanding, StationParticipant } from '../../types';

interface PublicRace {
  id: number;
  name: string;
  event_date: string;
  status: string;
  started_at?: string | null;
  finished_at?: string | null;
  timing_enabled: boolean;
  checkpoints: Checkpoint[];
  results_url?: string | null;
}

const props = defineProps<{
  race: PublicRace;
  token: string;
  participants: StationParticipant[];
  recentTimings: RecordedTap[];
  completedCount: number;
  standings: LiveStanding[];
  serverNow: string;
  undoWindowSeconds: number;
}>();

const roster = ref<StationParticipant[]>([]);
const copyParticipants = (rows: StationParticipant[]) => rows.map(item => ({ ...item, completed_checkpoint_ids: [...item.completed_checkpoint_ids] }));
roster.value = copyParticipants(props.participants);
watch(() => props.participants, rows => { roster.value = copyParticipants(rows); });

// The selected checkpoint survives reloads and is never silently defaulted.
const storageKey = `tt.checkpoint.${props.token}`;
const readStored = (): number | null => {
  const fromUrl = new URLSearchParams(window.location.search).get('checkpoint');
  const byCode = fromUrl ? props.race.checkpoints.find(cp => cp.code.toLowerCase() === fromUrl.toLowerCase()) : null;
  if (byCode) return byCode.id;
  try {
    const stored = Number(localStorage.getItem(storageKey));
    return props.race.checkpoints.some(cp => cp.id === stored) ? stored : null;
  } catch { return null; }
};
const checkpointId = ref<number | null>(readStored() ?? (props.race.checkpoints.length === 1 ? props.race.checkpoints[0].id : null));
const selectedCheckpoint = computed(() => props.race.checkpoints.find(cp => cp.id === checkpointId.value) ?? null);
watch(checkpointId, id => {
  if (!id) return;
  try { localStorage.setItem(storageKey, String(id)); } catch { /* private mode */ }
  const code = selectedCheckpoint.value?.code;
  if (code) {
    const url = new URL(window.location.href);
    url.searchParams.set('checkpoint', code);
    window.history.replaceState(window.history.state, '', url);
  }
});
watch(() => props.race.checkpoints, list => {
  if (checkpointId.value && !list.some(cp => cp.id === checkpointId.value)) checkpointId.value = null;
});

const recorder = useTimingRecorder({
  url: `/race/${props.token}/timings`,
  owner: `link:${props.token}`,
  race: () => props.race,
  checkpoint: () => selectedCheckpoint.value,
  participants: roster,
  undoRemote: async tap => {
    const { response, data } = await jsonRequest<{ message?: string }>(`/race/${props.token}/timings/${tap.client_uuid}/undo`, { method: 'POST' });
    return response.ok ? null : (data.message ?? tr('This tap can no longer be undone here. Ask the Organizer to correct it.'));
  },
  onRecorded: (_tap, info) => {
    if (!info.queued) router.reload({ only: ['standings', 'recentTimings', 'completedCount', ...(info.autoFinished ? ['race', 'serverNow'] : [])] });
  },
  onSynced: () => router.reload({ only: ['participants', 'recentTimings', 'standings', 'completedCount'] }),
});

// While taps are being saved or waiting to upload, polling keeps local state.
useRaceRefresh(() => ['race', 'standings', 'recentTimings', 'completedCount', 'serverNow',
  ...(recorder.saving.value.size === 0 && recorder.pending.value === 0 ? ['participants'] : [])]);

const view = ref<'timing' | 'standings' | 'latest'>('timing');
const pad = ref<InstanceType<typeof TimingPad>>();
const wake = useWakeLock();
const keepAwake = () => { if (wake.supported && !wake.enabled.value) void wake.toggle(); };
onMounted(() => {
  window.addEventListener('pointerdown', keepAwake, { once: true });
  if (!checkpointId.value && props.race.timing_enabled && !props.race.finished_at) pad.value?.openChooser();
});
onBeforeUnmount(() => window.removeEventListener('pointerdown', keepAwake));
</script>

<template>
  <Head :title="`${race.name} · ${$t('Race timing')}`"/>
  <AppLayout public-view>
    <div class="mx-auto max-w-3xl">
      <div class="mb-3 flex flex-wrap items-end justify-between gap-x-4 gap-y-1">
        <h1 class="text-3xl sm:text-4xl">{{ race.name }}</h1>
        <p class="text-sm muted">{{ $t('Race-day timing. No account needed.') }}</p>
      </div>

      <nav class="mb-3 grid grid-cols-3 gap-1 rounded-lg border border-outline bg-surface p-1" :aria-label="$t('Race-day views')">
        <button v-for="tab in (['timing','standings','latest'] as const)" :key="tab" type="button" class="min-h-11 rounded-md px-2 font-semibold" :class="view === tab ? 'bg-ink text-on-ink' : 'text-secondary hover:bg-raised'" :aria-pressed="view === tab" @click="view = tab">
          {{ tab === 'timing' ? $t('Timing') : tab === 'standings' ? $t('Standings') : $t('Latest times') }}
        </button>
      </nav>

      <section v-if="!race.timing_enabled" class="panel-pad text-center">
        <Icon name="power" class="mx-auto size-10 text-muted"/>
        <h2 class="mt-3 text-2xl">{{ $t('This race-day link is switched off') }}</h2>
        <p class="mt-1 muted">{{ $t('Ask the Organizer for the current link.') }}</p>
      </section>

      <template v-else>
        <div v-show="view === 'timing'">
          <div v-if="recorder.pending.value || !recorder.online.value" class="mb-3 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-warning/50 bg-warning/10 p-3 text-warning">
            <span class="inline-flex items-center gap-2 font-semibold">
              <Icon :name="recorder.online.value ? 'cloud-arrow-up' : 'wifi-off'"/>
              {{ recorder.online.value ? $t(':count taps waiting to upload', { count: recorder.pending.value }) : $t('Offline. Taps are saved on this phone.') }}
            </span>
            <button v-if="recorder.pending.value && recorder.online.value" type="button" class="btn-secondary !min-h-10" :disabled="recorder.queue.flushing.value" @click="recorder.sync()">{{ $t('Upload now') }}</button>
          </div>
          <p v-if="recorder.queue.error.value" role="alert" class="mb-3 rounded-lg bg-error/10 p-3 font-semibold text-error">{{ recorder.queue.error.value }}</p>

          <TimingPad
            ref="pad"
            v-model:checkpoint-id="checkpointId"
            :race="race"
            :server-now="serverNow"
            :checkpoints="race.checkpoints"
            :participants="roster"
            :recorder="recorder"
            :storage-key="`tt.keypad.${token}`"
          />

          <ul v-if="recorder.queue.items.value.length" class="mt-3 grid gap-2">
            <li v-for="item in recorder.queue.items.value" :key="item.client_uuid" class="rounded-lg border border-warning/50 bg-surface p-3">
              <div class="flex flex-wrap items-center justify-between gap-2">
                <strong>{{ item.label }} <span class="tabular font-normal muted">{{ formatDuration(item.elapsed_ms, 2) }}</span></strong>
                <button type="button" class="btn-quiet !min-h-10 text-error" @click="recorder.queue.discard(item.client_uuid)"><Icon name="trash"/>{{ $t('Discard') }}</button>
              </div>
              <p v-if="item.error" class="mt-1 text-sm text-warning">{{ item.error }}</p>
            </li>
          </ul>
        </div>

        <LiveStandings v-if="view === 'standings'" :standings="standings" :checkpoints="race.checkpoints" :started="!!race.started_at" :finished="!!race.finished_at"/>

        <section v-if="view === 'latest'" class="panel overflow-hidden">
          <div class="flex items-center justify-between gap-3 border-b border-outline p-4">
            <h2 class="text-2xl">{{ $t('Latest times') }}</h2>
            <span class="badge tabular">{{ $t(':count finished', { count: completedCount }) }}</span>
          </div>
          <ol v-if="recentTimings.length" class="divide-y divide-outline">
            <li v-for="timing in recentTimings" :key="timing.id ?? timing.recorded_at" class="flex items-center justify-between gap-3 px-4 py-3">
              <div class="min-w-0">
                <strong class="block truncate">{{ timing.entry?.display_name ?? bibLabel(timing.entry?.bib_number) }}</strong>
                <span class="text-sm muted">{{ $t(timing.checkpoint?.name ?? '') }}</span>
              </div>
              <span class="tabular display text-xl font-bold">{{ formatDuration(timing.elapsed_ms, 2) }}</span>
            </li>
          </ol>
          <p v-else class="p-6 text-center muted">{{ $t('No times yet.') }}</p>
          <div v-if="race.results_url" class="border-t border-outline p-4">
            <a :href="race.results_url" class="font-semibold text-accent underline">{{ $t('Open live results') }}</a>
          </div>
        </section>
      </template>
    </div>
  </AppLayout>
</template>
