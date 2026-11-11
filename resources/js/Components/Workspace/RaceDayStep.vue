<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Link, router, useForm, usePage } from '@inertiajs/vue3';
import Icon from '../Icon.vue';
import ConfirmDialog from '../ConfirmDialog.vue';
import DeleteTimingButton from '../DeleteTimingButton.vue';
import RaceClock from '../RaceClock.vue';
import LiveUpdatesStatus from '../LiveUpdatesStatus.vue';
import TimingPad from '../TimingPad.vue';
import RaceDayLink from './RaceDayLink.vue';
import { useTimingRecorder, type RecordedTap } from '../../Composables/useTimingRecorder';
import { usePermissions } from '../../Composables/usePermissions';
import { bibLabel, formatDuration, jsonRequest } from '../../lib';
import { tr } from '../../i18n';
import type { Checkpoint, PageProps, Race, StationParticipant } from '../../types';
import type { Readiness } from './types';

/** Step 3: the race-day link, the shared clock and timing from the workspace. */
const props = defineProps<{
  race: Race & { checkpoints: Checkpoint[] };
  readiness: Readiness;
  participants: StationParticipant[];
  recentTimings: RecordedTap[];
  completedCount: number;
  serverNow: string;
  allowedTimingCheckpointIds: number[];
  publicTimingUrl: string;
  publicTimingEnabled: boolean;
  publicTimingRotatedAt?: string | null;
}>();

const can = usePermissions();
const account = usePage<PageProps>().props.auth.user!;
const isAdmin = account.role === 'admin';
const visibleReadinessChecks = computed(() => props.readiness.checks.filter(check => check.key !== 'officials'));

const timingCheckpoints = computed(() => [...props.race.checkpoints]
  .sort((a, b) => a.sequence - b.sequence)
  .filter(cp => cp.is_active && cp.kind !== 'start' && props.allowedTimingCheckpointIds.includes(cp.id)));
const storageKey = `tt.workspace.checkpoint.${props.race.id}`;
const stored = (() => { try { return Number(localStorage.getItem(storageKey)); } catch { return 0; } })();
const checkpointId = ref<number | null>(timingCheckpoints.value.some(cp => cp.id === stored) ? stored : timingCheckpoints.value[0]?.id ?? null);
watch(checkpointId, id => { try { if (id) localStorage.setItem(storageKey, String(id)); } catch { /* private mode */ } });
watch(timingCheckpoints, list => { if (!list.some(cp => cp.id === checkpointId.value)) checkpointId.value = list[0]?.id ?? null; });

const participants = ref<StationParticipant[]>([]);
const copyParticipants = (rows: StationParticipant[]) => rows.map(p => ({ ...p, completed_checkpoint_ids: [...p.completed_checkpoint_ids] }));
participants.value = copyParticipants(props.participants);
watch(() => props.participants, rows => { participants.value = copyParticipants(rows); });

const recorder = useTimingRecorder({
  url: `/races/${props.race.id}/timings`,
  owner: account.id,
  race: () => props.race,
  checkpoint: () => timingCheckpoints.value.find(cp => cp.id === checkpointId.value) ?? null,
  participants,
  extraPayload: () => ({ operator_id: account.id, workspace: true }),
  undoRemote: async tap => {
    if (!tap.id) return tr('Reconnect before undoing a synced timing.');
    const { response, data } = await jsonRequest<{ message?: string }>(`/races/${props.race.id}/timings/${tap.id}/void`, { method: 'POST', body: JSON.stringify({ reason: tr('Accidental checkpoint tap') }) });
    if (response.ok) router.reload({ only: ['recentTimings', 'standings', 'completedCount'] });
    return response.ok ? null : (data.message ?? tr('Timing could not be removed.'));
  },
  onRecorded: (_tap, info) => {
    if (!info.queued) router.reload({ only: ['recentTimings', 'standings', 'completedCount', ...(info.autoFinished ? ['race', 'serverNow'] : [])] });
  },
  onSynced: () => router.reload({ only: ['participants', 'recentTimings', 'standings', 'completedCount', 'race', 'serverNow'] }),
});
defineExpose({ busy: computed(() => recorder.saving.value.size > 0 || recorder.pending.value > 0) });

const pendingClockAction = ref<'start' | 'finish' | null>(null);
const clockForm = useForm({});
const changeClock = () => {
  if (!pendingClockAction.value) return;
  clockForm.post(`/races/${props.race.id}/${pendingClockAction.value}`, { preserveScroll: true, onSuccess: () => { pendingClockAction.value = null; } });
};
</script>

<template>
  <div class="grid gap-5">
    <RaceDayLink v-if="isAdmin" :race-id="race.id" :url="publicTimingUrl" :enabled="publicTimingEnabled" :rotated-at="publicTimingRotatedAt"/>

    <section v-if="!race.started_at" class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3" :aria-label="$t('Race readiness')">
      <div v-for="check in visibleReadinessChecks" :key="check.key" class="rounded-md border border-outline bg-surface p-3">
        <div class="flex items-center gap-2">
          <Icon :name="check.ready ? 'circle-check' : 'alert'" :class="check.ready ? 'text-success' : check.required ? 'text-error' : 'text-warning'"/>
          <strong class="text-sm">{{ check.label }}</strong>
          <span v-if="!check.required" class="ml-auto text-xs muted">{{ $t("Recommended") }}</span>
        </div>
        <p class="mt-1 text-sm muted">{{ check.detail }}</p>
      </div>
    </section>

    <section class="flex flex-col gap-4 rounded-lg bg-ink p-4 text-on-ink sm:flex-row sm:items-center sm:justify-between sm:p-5">
      <div>
        <h3 class="text-lg opacity-85">{{ $t("Race clock") }}</h3>
        <RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow"/>
        <div class="mt-1 [&_*]:!text-on-ink/80"><LiveUpdatesStatus :race-id="race.id"/></div>
      </div>
      <div v-if="isAdmin" class="flex flex-col items-start gap-2 sm:items-end">
        <button v-if="!race.started_at" class="btn-primary min-w-40 !min-h-13 text-lg" :disabled="!readiness.ready_to_start" @click="pendingClockAction = 'start'"><Icon name="play"/>{{ $t("Start race") }}</button>
        <button v-else-if="!race.finished_at" class="btn-secondary !border-white/40 !bg-transparent !text-on-ink hover:!bg-white/10" @click="pendingClockAction = 'finish'"><Icon name="stop"/>{{ $t("End race now") }}</button>
        <span v-else class="badge !border-white/40 !bg-transparent !text-on-ink">{{ $t("Race finished") }}</span>
        <p v-if="!race.started_at && !readiness.ready_to_start" class="max-w-xs text-sm text-bike">{{ $t("Finish the required items above before starting the race.") }}</p>
        <p v-if="race.started_at && !race.finished_at" class="max-w-xs text-sm opacity-80 sm:text-right">{{ $t("The race ends automatically when every active participant has a finish time.") }}</p>
      </div>
    </section>

    <div v-if="can('timings.record') && race.started_at" class="grid gap-5 xl:grid-cols-[minmax(0,1fr)_380px] xl:items-start">
      <div>
        <p v-if="recorder.pending.value" class="mb-2 inline-flex items-center gap-2 text-sm font-semibold text-warning">
          <Icon name="cloud-arrow-up"/>{{ $t('Timings waiting to upload automatically: :count.', { count: recorder.pending.value }) }}
          <Link :href="`/races/${race.id}/station`" class="underline">{{ $t("Review device sync") }}</Link>
        </p>
        <TimingPad
          v-if="timingCheckpoints.length"
          v-model:checkpoint-id="checkpointId"
          :race="race"
          :server-now="serverNow"
          :checkpoints="timingCheckpoints"
          :participants="participants"
          :recorder="recorder"
          :locked="!isAdmin"
          :sticky="false"
          :timer="false"
          storage-key="tt.keypad.workspace"
        />
        <div v-else class="rounded-lg bg-warning/10 p-4 text-warning"><strong>{{ $t("No checkpoint assigned.") }}</strong><span class="mt-1 block text-sm">{{ $t("Ask the Organizer to assign this account before race day.") }}</span></div>
      </div>

      <section class="panel overflow-hidden">
        <div class="flex items-center justify-between gap-3 border-b border-outline px-4 py-3">
          <h3 class="text-xl">{{ $t("Latest times") }}</h3>
          <span class="badge tabular">{{ $t(':count finished', { count: completedCount }) }}</span>
        </div>
        <ol v-if="recentTimings.length" class="divide-y divide-outline">
          <li v-for="timing in recentTimings.slice(0, 12)" :key="timing.id ?? timing.client_uuid" class="flex items-center justify-between gap-2 px-4 py-2.5">
            <div class="min-w-0">
              <strong class="block truncate">{{ timing.entry?.display_name ?? bibLabel(timing.entry?.bib_number) }}</strong>
              <span class="text-sm muted">{{ $t(timing.checkpoint?.name ?? '') }}<template v-if="timing.operator"> · {{ timing.operator }}</template></span>
            </div>
            <div class="flex shrink-0 items-center gap-1">
              <span class="display tabular text-lg font-bold">{{ formatDuration(timing.elapsed_ms, 2) }}</span>
              <DeleteTimingButton v-if="timing.id && isAdmin" :race-id="race.id" :timing-id="timing.id" :label="`${timing.entry?.display_name ?? ''} · ${$t(timing.checkpoint?.name ?? 'Checkpoint')} · ${formatDuration(timing.elapsed_ms, 2)}`"/>
            </div>
          </li>
        </ol>
        <p v-else class="p-6 text-center muted">{{ $t("No times yet.") }}</p>
      </section>
    </div>
    <section v-else-if="!can('timings.record')" class="panel-pad"><p class="font-semibold">{{ $t("Your account cannot record timings.") }}</p></section>

    <ConfirmDialog
      v-if="pendingClockAction"
      :title="pendingClockAction === 'start' ? $t('Start the race?') : $t('End the race now?')"
      :message="pendingClockAction === 'start' ? $t('Start the shared clock now? Timing opens immediately for every checkpoint.') : $t('Stop the race now? Normally it ends automatically when the last active athlete finishes.')"
      :confirm-label="pendingClockAction === 'start' ? $t('Start race') : $t('End race')"
      :busy="clockForm.processing"
      @cancel="pendingClockAction = null; clockForm.clearErrors()"
      @confirm="changeClock"
    >
      <p v-if="pendingClockAction === 'finish'" class="mt-3 text-sm">{{ $t('Competing athletes without a finish time will automatically be marked DNF. DNS and disqualified statuses stay unchanged.') }}</p>
      <p v-if="Object.keys(clockForm.errors).length" class="mt-3 text-error">{{ Object.values(clockForm.errors)[0] }}</p>
    </ConfirmDialog>
  </div>
</template>
