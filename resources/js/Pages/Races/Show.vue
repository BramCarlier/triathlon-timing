<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import Icon from '../../Components/Icon.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import LiveStandings from '../../Components/LiveStandings.vue';
import CourseStep from '../../Components/Workspace/CourseStep.vue';
import AthletesStep from '../../Components/Workspace/AthletesStep.vue';
import RaceDayStep from '../../Components/Workspace/RaceDayStep.vue';
import { usePermissions } from '../../Composables/usePermissions';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { tr } from '../../i18n';
import type { RecordedTap } from '../../Composables/useTimingRecorder';
import type { Checkpoint, LiveStanding, PageProps, Race, StationParticipant } from '../../types';
import type { AthleteChoice, CheckpointAssignment, Official, Readiness } from '../../Components/Workspace/types';

type Step = 'prepare' | 'participants' | 'race-day' | 'finish';

const props = defineProps<{
  race: Race & { checkpoints: Checkpoint[]; entries_count?: number; results_published_at?: string | null; public_results_token?: string | null };
  publicTimingUrl: string;
  publicTimingEnabled: boolean;
  publicTimingRotatedAt?: string | null;
  officials: Official[];
  checkpointAssignments: CheckpointAssignment[];
  allowedTimingCheckpointIds: number[];
  mailConfigured: boolean;
  athleteOptions: AthleteChoice[];
  readiness: Readiness;
  participants: StationParticipant[];
  recentTimings: RecordedTap[];
  completedCount: number;
  standings: LiveStanding[];
  serverNow: string;
}>();

const can = usePermissions();
const account = computed(() => usePage<PageProps>().props.auth.user);
const isAdmin = computed(() => account.value?.role === 'admin');
const raceDay = ref<InstanceType<typeof RaceDayStep>>();

// Keep the athlete list stable while a tap is being saved or waits to upload.
useRaceRefresh(() => ['race', 'recentTimings', 'standings', 'completedCount', 'serverNow', 'checkpointAssignments', 'allowedTimingCheckpointIds',
  ...(raceDay.value?.busy ? [] : ['participants'])]);

const requiredSetupReady = computed(() => props.readiness.checks.filter(check => check.required && check.key !== 'participants').every(check => check.ready));
const participantsReady = computed(() => props.readiness.participant_count > 0);

const initialStep = (): Step => {
  if (props.race.finished_at) return 'finish';
  if (props.race.started_at) return 'race-day';
  if (!requiredSetupReady.value) return 'prepare';
  if (!participantsReady.value) return 'participants';
  return 'race-day';
};
const openStep = ref<Step | null>(initialStep());
const toggleStep = (step: Step) => { openStep.value = openStep.value === step ? null : step; };
const goTo = (step: Step) => {
  openStep.value = step;
  requestAnimationFrame(() => document.getElementById(step)?.scrollIntoView({ behavior: 'smooth', block: 'start' }));
};
watch([() => props.race.started_at, () => props.race.finished_at], ([started, finished], [oldStarted, oldFinished]) => {
  if (finished && !oldFinished) openStep.value = 'finish';
  else if (started && !oldStarted) openStep.value = 'race-day';
});

const status = computed(() => {
  if (props.race.finished_at) return { title: tr('Race finished'), detail: tr('Review results or open Corrections & station health for a genuine timing correction.'), icon: 'flag-checkered', tone: 'done' };
  if (props.race.started_at) return { title: tr('Race is live'), detail: tr('Timers record with the race-day link. You can also time from here.'), icon: 'stopwatch', tone: 'live' };
  const missing = props.readiness.checks.find(check => check.required && !check.ready);
  if (missing?.key === 'participants') return { title: tr('Next: add athletes'), detail: missing.detail, icon: 'users', tone: 'todo' };
  if (missing) return { title: tr('Next: finish course setup'), detail: missing.detail, icon: 'route', tone: 'todo' };
  return { title: tr('Ready to start'), detail: tr('The course and athletes are ready. Start the shared clock when the race begins.'), icon: 'play', tone: 'ready' };
});

const steps = computed(() => [
  ...(!props.race.started_at && isAdmin.value ? [{ id: 'prepare' as Step, label: tr('Course'), ready: requiredSetupReady.value }] : []),
  ...(isAdmin.value ? [{ id: 'participants' as Step, label: tr('Athletes'), ready: participantsReady.value }] : []),
  { id: 'race-day' as Step, label: props.race.started_at ? tr('Timing') : tr('Start & timing'), ready: !!props.race.started_at },
  { id: 'finish' as Step, label: tr('Results'), ready: !!props.race.finished_at },
]);

const publishing = ref(false);
const publication = useForm({ published: !props.race.results_published_at });
const publish = () => {
  publication.published = !props.race.results_published_at;
  publication.post(`/races/${props.race.id}/publication`, { preserveScroll: true, onSuccess: () => { publishing.value = false; } });
};

const deleting = ref(false);
const deleteForm = useForm({});
const deleteRace = () => deleteForm.delete(`/races/${props.race.id}`, { onSuccess: () => { deleting.value = false; } });
</script>

<template>
  <Head :title="race.name"/>
  <AppLayout :title="race.name">
    <section class="mb-5 panel-pad">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-start gap-3">
          <span class="grid size-12 shrink-0 place-items-center rounded-md" :class="status.tone === 'live' ? 'bg-success text-white' : status.tone === 'done' ? 'bg-ink text-on-ink' : 'bg-buoy text-on-buoy'">
            <Icon :name="status.icon" class="size-6"/>
          </span>
          <div>
            <h2 class="text-3xl">{{ status.title }}</h2>
            <p class="mt-0.5 text-secondary">{{ status.detail }}</p>
          </div>
        </div>
        <Link href="/guide" class="btn-secondary self-start lg:self-auto"><Icon name="circle-question"/>{{ $t("Guide") }}</Link>
      </div>

      <ol class="mt-5 grid grid-flow-col auto-cols-fr gap-1.5 sm:gap-2" :aria-label="$t('Race setup progress')">
        <li v-for="(step, index) in steps" :key="step.id">
          <button type="button" class="flex min-h-14 w-full flex-col items-center justify-center gap-1 rounded-md border px-1.5 py-2 text-center text-sm transition-colors hover:bg-raised sm:flex-row sm:justify-start sm:gap-2.5 sm:px-3 sm:text-left sm:text-base" :class="openStep === step.id ? 'border-buoy' : 'border-outline'" @click="goTo(step.id)">
            <span class="display grid size-8 shrink-0 place-items-center rounded-full text-lg font-bold" :class="step.ready ? 'bg-success text-white' : 'bg-raised text-secondary'">
              <Icon v-if="step.ready" name="check" class="size-5"/><template v-else>{{ index + 1 }}</template>
            </span>
            <strong class="min-w-0 truncate">{{ step.label }}</strong>
          </button>
        </li>
      </ol>
    </section>

    <section v-if="isAdmin && !race.started_at" id="prepare" class="mb-4 scroll-mt-28 rounded-lg border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left sm:px-5" :aria-expanded="openStep === 'prepare'" @click="toggleStep('prepare')">
        <div>
          <h2 class="text-2xl">{{ $t("Course") }}</h2>
          <p class="mt-0.5 text-sm muted">{{ $t("The default triathlon checkpoints are ready to use. Only change them if this race needs something different.") }}</p>
        </div>
        <Icon :name="openStep === 'prepare' ? 'chevron-up' : 'chevron-down'" class="size-6"/>
      </button>
      <div v-show="openStep === 'prepare'" class="border-t border-outline p-4 sm:p-5">
        <CourseStep :race="race" :officials="officials" :checkpoint-assignments="checkpointAssignments" :mail-configured="mailConfigured"/>
      </div>
    </section>

    <section v-if="isAdmin" id="participants" class="mb-4 scroll-mt-28 rounded-lg border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left sm:px-5" :aria-expanded="openStep === 'participants'" @click="toggleStep('participants')">
        <div>
          <h2 class="text-2xl">{{ $t("Athletes") }}</h2>
          <p class="mt-0.5 text-sm muted">{{ $t(':count added', { count: race.entries_count ?? 0 }) }}</p>
        </div>
        <Icon :name="openStep === 'participants' ? 'chevron-up' : 'chevron-down'" class="size-6"/>
      </button>
      <div v-show="openStep === 'participants'" class="border-t border-outline p-4 sm:p-5">
        <AthletesStep :race="race" :participants="participants" :athlete-options="athleteOptions"/>
      </div>
    </section>

    <section id="race-day" class="mb-4 scroll-mt-28 rounded-lg border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left sm:px-5" :aria-expanded="openStep === 'race-day'" @click="toggleStep('race-day')">
        <div>
          <h2 class="text-2xl">{{ race.started_at ? $t('Timing') : $t('Start & timing') }}</h2>
          <p class="mt-0.5 text-sm muted">{{ race.started_at ? $t(':done of :total have a finish time', { done: completedCount, total: race.entries_count ?? 0 }) : $t('Share the race-day link, then start the clock.') }}</p>
        </div>
        <Icon :name="openStep === 'race-day' ? 'chevron-up' : 'chevron-down'" class="size-6"/>
      </button>
      <div v-show="openStep === 'race-day'" class="border-t border-outline p-4 sm:p-5">
        <RaceDayStep
          ref="raceDay"
          :race="race"
          :readiness="readiness"
          :participants="participants"
          :recent-timings="recentTimings"
          :completed-count="completedCount"
          :server-now="serverNow"
          :allowed-timing-checkpoint-ids="allowedTimingCheckpointIds"
          :public-timing-url="publicTimingUrl"
          :public-timing-enabled="publicTimingEnabled"
          :public-timing-rotated-at="publicTimingRotatedAt"
        />
      </div>
    </section>

    <LiveStandings v-if="race.started_at" :standings="standings" :checkpoints="race.checkpoints" :started="!!race.started_at" :finished="!!race.finished_at" class="mb-4"/>

    <section id="finish" class="mb-4 scroll-mt-28 rounded-lg border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left sm:px-5" :aria-expanded="openStep === 'finish'" @click="toggleStep('finish')">
        <div>
          <h2 class="text-2xl">{{ $t("Results") }}</h2>
          <p class="mt-0.5 text-sm muted">{{ $t(':done of :total have a finish time', { done: completedCount, total: race.entries_count ?? 0 }) }}</p>
        </div>
        <Icon :name="openStep === 'finish' ? 'chevron-up' : 'chevron-down'" class="size-6"/>
      </button>
      <div v-show="openStep === 'finish'" class="border-t border-outline p-4 sm:p-5">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 class="text-xl">{{ race.finished_at ? $t('Race complete') : $t('Results update while the race is live') }}</h3>
            <p class="mt-1 muted">{{ $t("Open the results table for places and split times.") }}</p>
          </div>
          <Link :href="`/races/${race.id}/results`" class="btn-primary"><Icon name="trophy"/>{{ $t("View results") }}</Link>
        </div>
        <div v-if="isAdmin && race.finished_at" class="mt-5 flex flex-wrap items-center justify-between gap-3 border-t border-outline pt-5">
          <div>
            <h3 class="text-xl">{{ $t("Public results") }}</h3>
            <p class="mt-1 text-sm muted">{{ race.results_published_at ? $t('Published') : $t('Private') }}</p>
            <a v-if="race.results_published_at && race.public_results_token" :href="`/live/${race.public_results_token}`" target="_blank" rel="noopener" class="mt-1 inline-flex items-center gap-1 font-semibold text-accent underline">{{ $t("Open public leaderboard") }}<Icon name="arrow-up-right-from-square" class="size-4"/></a>
          </div>
          <button class="btn-secondary" @click="publishing = true"><Icon :name="race.results_published_at ? 'eye-slash' : 'globe'"/>{{ race.results_published_at ? $t('Unpublish') : $t('Publish results') }}</button>
        </div>
      </div>
    </section>

    <details class="panel-pad">
      <summary class="inline-flex items-center gap-2 font-semibold"><Icon name="toolbox"/>{{ $t("More tools") }}</summary>
      <p class="mt-2 text-sm muted">{{ $t("Only use these when the normal race flow is not enough.") }}</p>
      <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <Link v-if="can('timings.record')" :href="`/races/${race.id}/station`" class="rounded-md border border-outline p-4 hover:bg-raised"><strong class="inline-flex items-center gap-2"><Icon name="stopwatch"/>{{ $t("Full-screen timing station") }}</strong><p class="mt-2 text-sm muted">{{ $t("The same race timing workflow with extra offline and device recovery controls.") }}</p></Link>
        <Link v-if="isAdmin" :href="`/races/${race.id}/participants`" class="rounded-md border border-outline p-4 hover:bg-raised"><strong class="inline-flex items-center gap-2"><Icon name="users"/>{{ $t("Full participant list") }}</strong><p class="mt-2 text-sm muted">{{ $t("Find athletes, assign bib numbers or correct result statuses.") }}</p></Link>
        <Link v-if="isAdmin && race.started_at" :href="`/races/${race.id}/control`" class="rounded-md border border-outline p-4 hover:bg-raised"><strong class="inline-flex items-center gap-2"><Icon name="screwdriver-wrench"/>{{ $t("Corrections & station health") }}</strong><p class="mt-2 text-sm muted">{{ $t("Correct recorded times or check which timing stations are online.") }}</p></Link>
      </div>
      <div v-if="isAdmin && (!race.started_at || race.finished_at)" class="mt-5 border-t border-error/30 pt-5"><button class="btn-danger" @click="deleting = true"><Icon name="trash"/>{{ $t("Delete race") }}</button></div>
    </details>

    <ConfirmDialog
      v-if="publishing"
      :title="race.results_published_at ? $t('Unpublish results?') : $t('Publish results?')"
      :message="race.results_published_at ? $t('The public link will stop working.') : $t('Anyone with the link will be able to view participant names and results.')"
      :confirm-label="race.results_published_at ? $t('Unpublish') : $t('Publish results')"
      :busy="publication.processing"
      @cancel="publishing = false"
      @confirm="publish"
    />
    <ConfirmDialog
      v-if="deleting"
      :title="$t('Delete race?')"
      :message="$t('Move :name to Deleted races?', { name: race.name })"
      :confirm-label="$t('Delete race')"
      :busy="deleteForm.processing"
      @cancel="deleting = false"
      @confirm="deleteRace"
    />
  </AppLayout>
</template>
