<script setup lang="ts">
import { tr } from '../../i18n';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import RaceClock from '../../Components/RaceClock.vue';
import LiveUpdatesStatus from '../../Components/LiveUpdatesStatus.vue';
import RaceAthletePicker from '../../Components/RaceAthletePicker.vue';
import { usePermissions } from '../../Composables/usePermissions';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { useOfflineTimingQueue } from '../../Composables/useOfflineTimingQueue';
import { checkpointDistanceText } from '../../checkpointDistance';
import { bibLabel, formatDuration, jsonRequest, uuid } from '../../lib';
import type { Checkpoint, PageProps, Race, StationParticipant } from '../../types';

interface Official {
  id: number;
  name: string;
  email: string;
  role?: 'admin'|'organizer';
}

interface CheckpointAssignment {
  id: number;
  race_id: number;
  checkpoint_id: number;
  user_id: number;
  user: Official;
  checkpoint?: { id:number; name:string };
}

interface AthleteChoice {
  id: number;
  full_name: string;
  first_name: string;
  last_name: string;
  email: string | null;
  club: string | null;
  race_count: number;
  already_in_race: boolean;
  races: Array<{ id:number; name:string; event_date:string }>;
  recent_bibs: string[];
}

interface ReadinessCheck { key:string; label:string; ready:boolean; required:boolean; detail:string }
interface Readiness { ready_to_start:boolean; checks:ReadinessCheck[]; participant_count:number; unassigned_checkpoint_count:number }

interface Timing {
  id?: number;
  client_uuid?: string;
  elapsed_ms: number;
  recorded_at: string;
  entry?: { id:number; bib_number:string|null; display_name?:string };
  checkpoint?: { id?:number; name:string };
  operator?: { name:string };
}

type Step = 'prepare' | 'participants' | 'race-day' | 'finish';

const props = defineProps<{
  race: Race & { checkpoints: Checkpoint[]; entries_count?: number };
  publicTimingUrl?: string|null;
  officials: Official[];
  checkpointAssignments: CheckpointAssignment[];
  allowedTimingCheckpointIds: number[];
  mailConfigured: boolean;
  athleteOptions: AthleteChoice[];
  readiness: Readiness;
  participants: StationParticipant[];
  recentTimings: Timing[];
  completedCount: number;
  serverNow: string;
}>();

const can = usePermissions();
const page = usePage<PageProps>();
const account = computed(() => page.props.auth.user);
const isAdmin = computed(() => account.value?.role === 'admin');
const showOfficialTools = ref(false);
const copiedRaceDayLink = ref(false);
const raceDayLink = computed(() => props.publicTimingUrl ?? null);
const visibleReadinessChecks = computed(() => props.readiness.checks.filter(check => check.key !== 'officials'));
const copyRaceDayLink = async () => {
  if (!raceDayLink.value) return;
  try {
    await navigator.clipboard.writeText(new URL(raceDayLink.value, window.location.origin).toString());
    copiedRaceDayLink.value = true;
    window.setTimeout(() => { copiedRaceDayLink.value = false; }, 2500);
  } catch {
    copiedRaceDayLink.value = false;
  }
};

useRaceRefresh(() => ['race', 'participants', 'recentTimings', 'completedCount', 'serverNow', 'checkpointAssignments', 'allowedTimingCheckpointIds']);

const orderedCheckpoints = computed(() => [...props.race.checkpoints].sort((a,b) => a.sequence-b.sequence));
const setupCheckpoints = computed(() => orderedCheckpoints.value.filter(cp => cp.kind !== 'start'));
const activeTimingCheckpoints = computed(() => orderedCheckpoints.value.filter(cp =>
  cp.is_active
  && cp.kind !== 'start'
  && props.allowedTimingCheckpointIds.includes(cp.id)
));
const requiredSetupReady = computed(() => props.readiness.checks.filter(check => check.required && check.key !== 'participants').every(check => check.ready));
const participantsReady = computed(() => props.readiness.participant_count > 0);

const initialStep = ():Step => {
  if (props.race.finished_at) return 'finish';
  if (props.race.started_at) return 'race-day';
  if (!requiredSetupReady.value) return 'prepare';
  if (!participantsReady.value) return 'participants';
  return 'race-day';
};
const openStep = ref<Step|null>(initialStep());
const toggleStep = (step:Step) => { openStep.value = openStep.value === step ? null : step; };
watch([() => props.race.started_at, () => props.race.finished_at], ([started, finished], [oldStarted, oldFinished]) => {
  if (finished && !oldFinished) openStep.value = 'finish';
  else if (started && !oldStarted) openStep.value = 'race-day';
});

const workflowState = computed(() => {
  if (props.race.finished_at) return { title:tr('Race finished'), detail:tr('Review results or open Corrections & station health for a genuine timing correction.'), icon:'fa-solid fa-flag-checkered' };
  if (props.race.started_at) return { title:tr('Race is live'), detail:tr('Record timings here and switch checkpoints when needed.'), icon:'fa-solid fa-stopwatch' };
  const missingRequired = props.readiness.checks.find(check => check.required && !check.ready);
  if (missingRequired?.key === 'participants') return { title:tr('Next: add athletes'), detail:missingRequired.detail, icon:'fa-solid fa-users' };
  if (missingRequired) return { title:tr('Next: finish course setup'), detail:missingRequired.detail, icon:'fa-solid fa-route' };
  return { title:tr('Ready to start'), detail:tr('The course and athletes are ready. Start the shared clock when the race begins.'), icon:'fa-solid fa-play' };
});

const raceForm = useForm({
  name: props.race.name,
  event_date: props.race.event_date,
  timezone: props.race.timezone,
  status: props.race.status,
  swim_km: Number(props.race.settings?.swim_km ?? 1),
  bike_km: Number(props.race.settings?.bike_km ?? 35),
  run_km: Number(props.race.settings?.run_km ?? 8),
});
const editingRaceDetails = ref(false);
const saveRace = () => raceForm.put(`/races/${props.race.id}`, { preserveScroll:true, onSuccess:()=>{editingRaceDetails.value=false;} });

const checkpointFormOpen = ref(false);
const editingCheckpoint = ref<Checkpoint|null>(null);
const suggestedSequence = () => {
  const list = orderedCheckpoints.value;
  const finish = list.find(cp => cp.kind === 'finish');
  if (finish) {
    const before = list.filter(cp => cp.sequence < finish.sequence).at(-1);
    if (before && finish.sequence - before.sequence > 1) return Math.floor((before.sequence + finish.sequence) / 2);
  }
  return Math.max(0, ...list.map(cp => cp.sequence)) + 10;
};
const checkpoint = useForm({
  name: '',
  code: '',
  sequence: suggestedSequence(),
  discipline: 'run',
  kind: tr('split'),
  distance_km: '',
  is_required: true,
  is_active: true,
});
const beginAddCheckpoint = () => {
  assigningCheckpoint.value = null;
  officialForm.clearErrors();
  editingCheckpoint.value = null;
  checkpoint.clearErrors();
  checkpoint.name = '';
  checkpoint.code = '';
  checkpoint.sequence = suggestedSequence();
  checkpoint.discipline = 'run';
  checkpoint.kind = tr('split');
  checkpoint.distance_km = '';
  checkpoint.is_required = true;
  checkpoint.is_active = true;
  checkpointFormOpen.value = true;
};
const beginEditCheckpoint = (cp:Checkpoint) => {
  assigningCheckpoint.value = null;
  officialForm.clearErrors();
  editingCheckpoint.value = cp;
  checkpoint.clearErrors();
  checkpoint.name = cp.name;
  checkpoint.code = cp.code;
  checkpoint.sequence = cp.sequence;
  checkpoint.discipline = cp.discipline ?? '';
  checkpoint.kind = cp.kind;
  checkpoint.distance_km = cp.distance_km ?? '';
  checkpoint.is_required = cp.is_required;
  checkpoint.is_active = cp.is_active;
  checkpointFormOpen.value = true;
};
const closeCheckpointForm = () => {
  checkpointFormOpen.value = false;
  editingCheckpoint.value = null;
  checkpoint.clearErrors();
};
const saveCheckpoint = () => {
  const options = { preserveScroll:true, onSuccess:closeCheckpointForm };
  if (editingCheckpoint.value) checkpoint.put(`/races/${props.race.id}/checkpoints/${editingCheckpoint.value.id}`, options);
  else checkpoint.post(`/races/${props.race.id}/checkpoints`, options);
};
const removingCheckpoint = ref<Checkpoint|null>(null);
const checkpointError = ref('');
const confirmCheckpointRemoval = () => {
  if (!removingCheckpoint.value) return;
  router.delete(`/races/${props.race.id}/checkpoints/${removingCheckpoint.value.id}`, {
    preserveScroll:true,
    onSuccess:() => { removingCheckpoint.value = null; checkpointError.value = ''; },
    onError:errors => { checkpointError.value = String(Object.values(errors)[0] ?? 'Unable to delete checkpoint'); },
  });
};

const assigningCheckpoint = ref<Checkpoint|null>(null);
const officialForm = useForm({
  checkpoint_id: null as number|null,
  user_id: null as number|null,
  name: '',
  email: '',
  delivery: props.mailConfigured ? 'email' : 'manual',
  password: '',
});
const assignmentsFor = (checkpointId:number) => props.checkpointAssignments.filter(item => item.checkpoint_id === checkpointId);
const assignmentForOfficial = (officialId:number) => props.checkpointAssignments.find(item => item.user_id === officialId);
const officialFilterTokens = computed(() => `${officialForm.name} ${officialForm.email}`.trim().toLowerCase().split(/\s+/).filter(Boolean));
const availableOfficials = computed(() => props.officials.filter(official => {
  const haystack = `${official.name} ${official.email}`.toLowerCase();
  return officialFilterTokens.value.every(token => haystack.includes(token));
}));
const selectedOfficial = computed(() => props.officials.find(official => official.id === officialForm.user_id) ?? null);
const updateOfficialField = (field:'name'|'email', event:Event) => {
  officialForm.user_id = null;
  const value = (event.target as HTMLInputElement).value;
  if (field === 'name') officialForm.name = value;
  else officialForm.email = value;
};
const selectOfficial = (official:Official) => {
  officialForm.user_id = official.id;
  officialForm.name = official.name;
  officialForm.email = official.email;
  officialForm.password = '';
  officialForm.clearErrors();
};
const clearOfficialSelection = () => {
  officialForm.user_id = null;
  officialForm.name = '';
  officialForm.email = '';
  officialForm.password = '';
  officialForm.clearErrors();
};
const beginAssignOfficial = (cp:Checkpoint) => {
  closeCheckpointForm();
  officialForm.reset();
  officialForm.clearErrors();
  officialForm.checkpoint_id = cp.id;
  officialForm.delivery = props.mailConfigured ? 'email' : 'manual';
  assigningCheckpoint.value = cp;
};
const closeOfficialForm = () => {
  assigningCheckpoint.value = null;
  officialForm.clearErrors();
};
const assignOfficial = () => officialForm.post(`/races/${props.race.id}/official-assignments`, {
  preserveScroll:true,
  onSuccess:closeOfficialForm,
});
const removeOfficial = (official:Official) => router.delete(`/races/${props.race.id}/official-assignments/${official.id}`, { preserveScroll:true });

const participantForm = useForm({
  bib_number: '',
  type: 'solo' as 'solo'|'relay',
  team_name: '',
  category: '',
  members: [{ discipline:'swim', athlete_id:null as number|null, first_name:'', last_name:'', email:'', club:'' }],
});
watch(() => participantForm.type, type => {
  participantForm.members = type === 'solo'
    ? [{ discipline:'swim', athlete_id:null, first_name:'', last_name:'', email:'', club:'' }]
    : ['swim','bike','run'].map(discipline => ({ discipline, athlete_id:null as number|null, first_name:'', last_name:'', email:'', club:'' }));
});
const addParticipant = () => participantForm.post(`/races/${props.race.id}/participants`, {
  preserveScroll:true,
  onSuccess:() => participantForm.reset(),
});
const disciplineLabel = (discipline:string) => discipline.charAt(0).toUpperCase()+discipline.slice(1);

const timingParticipants = ref(props.participants.map(p => ({...p, completed_checkpoint_ids:[...p.completed_checkpoint_ids]})));
const recent = ref<Timing[]>(props.recentTimings.map(t => ({...t})));
const localCompletedCount = ref(props.completedCount);
watch(() => props.participants, value => { timingParticipants.value = value.map(p => ({...p, completed_checkpoint_ids:[...p.completed_checkpoint_ids]})); });
watch(() => props.recentTimings, value => { recent.value = value.map(t => ({...t})); });
watch(() => props.completedCount, value => { localCompletedCount.value = value; });

const selectedCheckpointId = ref<number|null>(activeTimingCheckpoints.value[0]?.id ?? null);
watch(activeTimingCheckpoints, checkpoints => {
  if (!checkpoints.some(cp => cp.id === selectedCheckpointId.value)) selectedCheckpointId.value = checkpoints[0]?.id ?? null;
});
const selectedCheckpoint = computed(() => activeTimingCheckpoints.value.find(cp => cp.id === Number(selectedCheckpointId.value)) ?? null);
const timingSearch = ref('');
const filteredTimingParticipants = computed(() => {
  const term = timingSearch.value.trim().toLowerCase();
  const rows = term
    ? timingParticipants.value.filter(p =>
        (p.bib_number ?? '').toLowerCase().includes(term)
        || p.name.toLowerCase().includes(term)
        || p.members.some(member => member.name.toLowerCase().includes(term)))
    : timingParticipants.value;
  return rows.slice(0, 50);
});
const workspaceOnline = ref(navigator.onLine);
const {items:workspaceQueued,pending:workspacePending,queue:queueWorkspaceTiming,flush:flushWorkspaceTiming,refresh:refreshWorkspaceQueue} = useOfflineTimingQueue(props.race.id, account.value!.id);
const participantRecorded = (participant:StationParticipant) => !!selectedCheckpoint.value && (
  participant.completed_checkpoint_ids.includes(selectedCheckpoint.value.id)
  || workspaceQueued.value.some(item=>Number(item.payload.entry_id)===participant.id && Number(item.payload.checkpoint_id)===selectedCheckpoint.value!.id)
);
const timingSaving = ref(new Set<number>());
const timingFeedback = ref<{type:'ok'|'error'|'offline';message:string}|null>(null);
const timingConfirmation = ref<{participant:StationParticipant;clientUuid:string;message:string}|null>(null);
const showTimingFeedback = (type:'ok'|'error'|'offline', message:string) => {
  timingFeedback.value = {type,message};
  window.setTimeout(() => { if (timingFeedback.value?.message === message) timingFeedback.value = null; }, 4500);
};

async function recordTiming(participant:StationParticipant, override=false, clientUuid=uuid()) {
  if (!selectedCheckpoint.value || timingSaving.value.has(participant.id)) return;
  if (!props.race.started_at) { showTimingFeedback('error','Start the race before recording times.'); return; }
  if (props.race.finished_at) { showTimingFeedback('error',tr('The race is finished.')); return; }
  if (participant.status && participant.status !== 'registered') { showTimingFeedback('error',`${participant.name} is marked ${participant.status.toUpperCase()}.`); return; }
  if (participantRecorded(participant)) { showTimingFeedback('error',`${participant.name} is already recorded here.`); return; }

  const checkpointId=selectedCheckpoint.value.id;
  const payload={
    operator_id:account.value!.id,
    entry_id:participant.id,
    checkpoint_id:checkpointId,
    client_uuid:clientUuid,
    observed_at:new Date().toISOString(),
    source:'online',
    workspace:true,
    override_warning:override,
  };
  const elapsed=Math.max(0,Date.now()-new Date(props.race.started_at).getTime());
  const saveOffline=async()=>{
    await queueWorkspaceTiming(`/races/${props.race.id}/timings`,payload,participant.name,elapsed);
    timingSearch.value='';
    showTimingFeedback('offline',`${participant.name} saved on this device and will upload automatically.`);
  };

  timingSaving.value.add(participant.id);
  try {
    if(!workspaceOnline.value){await saveOffline();return;}
    let result;
    try {
      result=await jsonRequest<{timing?:Timing;message?:string;warning?:boolean;missing_checkpoints?:string[];auto_finished?:boolean}>(`/races/${props.race.id}/timings`, {
        method:'POST',body:JSON.stringify(payload),signal:AbortSignal.timeout(12000),
      });
    } catch { await saveOffline(); return; }

    const {response,data}=result;
    if (response.ok && data.timing) {
      participant.completed_checkpoint_ids.push(checkpointId);
      recent.value.unshift(data.timing);
      recent.value=recent.value.slice(0,12);
      if(selectedCheckpoint.value?.kind==='finish')localCompletedCount.value+=1;
      timingSearch.value='';
      showTimingFeedback('ok',data.message??tr('Time recorded.'));
      if(data.auto_finished)router.reload({only:['race','serverNow','completedCount']});
      return;
    }
    if(response.status===409&&data.warning){
      timingConfirmation.value={participant,clientUuid,message:`${data.message??tr('An earlier checkpoint is missing.')} ${(data.missing_checkpoints??[]).join(', ')} Record anyway?`};
      return;
    }
    if((response.ok&&!data.timing)||response.status>=500||[401,403,408,419,429].includes(response.status)){await saveOffline();return;}
    showTimingFeedback('error',data.message??'The time could not be recorded.');
  } catch {
    showTimingFeedback('error','Timing was NOT saved: device storage is unavailable. Open the full-screen timing station and check device storage.');
  } finally {
    timingSaving.value.delete(participant.id);
  }
}
const confirmTimingOverride = () => {
  const item = timingConfirmation.value;
  timingConfirmation.value = null;
  if (item) void recordTiming(item.participant, true, item.clientUuid);
};
const syncWorkspaceQueue=async()=>{if(await flushWorkspaceTiming())router.reload({only:['participants','recentTimings','completedCount','race','serverNow']});};
const handleWorkspaceOnline=()=>{workspaceOnline.value=true;void syncWorkspaceQueue();};
const handleWorkspaceOffline=()=>{workspaceOnline.value=false;};
onMounted(async()=>{window.addEventListener('online',handleWorkspaceOnline);window.addEventListener('offline',handleWorkspaceOffline);try{await refreshWorkspaceQueue();await syncWorkspaceQueue();}catch{/* full-screen timing exposes detailed storage recovery */}});
onBeforeUnmount(()=>{window.removeEventListener('online',handleWorkspaceOnline);window.removeEventListener('offline',handleWorkspaceOffline);});

const pendingClockAction = ref<'start'|'finish'|null>(null);
const clockForm = useForm({});
const changeClock = () => {
  if (!pendingClockAction.value) return;
  clockForm.post(`/races/${props.race.id}/${pendingClockAction.value}`, {
    preserveScroll:true,
    onSuccess:() => { pendingClockAction.value = null; },
  });
};

const publishing = ref(false);
const publication = useForm({ published: !props.race.results_published_at });
const publish = () => {
  publication.published = !props.race.results_published_at;
  publication.post(`/races/${props.race.id}/publication`, { preserveScroll:true, onSuccess:() => { publishing.value=false; } });
};

const deleting = ref(false);
const deleteForm = useForm({});
const deleteRace = () => deleteForm.delete(`/races/${props.race.id}`, { onSuccess:() => { deleting.value=false; } });
</script>

<template>
  <Head :title="race.name"/>
  <AppLayout :title="race.name">
    <section class="panel-pad mb-5">
      <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
        <div class="flex items-start gap-3">
          <span class="grid size-11 shrink-0 place-items-center rounded-2xl bg-cyan-400 text-slate-950">
            <i :class="workflowState.icon" aria-hidden="true"></i>
          </span>
          <div>
            <h2 class="text-xl font-bold">{{ workflowState.title }}</h2>
            <p class="mt-1 muted">{{ workflowState.detail }}</p>
          </div>
        </div>
        <Link href="/guide" class="btn-secondary self-start lg:self-auto"><i class="fa-solid fa-circle-question" aria-hidden="true"></i>{{ $t("Guide") }}</Link>
      </div>

      <div v-if="isAdmin && !race.started_at" class="mt-5 grid gap-2 sm:grid-cols-3" :aria-label="$t('Race setup progress')">
        <button type="button" class="rounded-xl border border-outline p-3 text-left hover:bg-raised" @click="openStep='prepare'">
          <span class="text-xs font-bold uppercase tracking-wider" :class="requiredSetupReady?'text-success':'text-warning'">{{ requiredSetupReady?$t('Ready'):'1' }}</span>
          <strong class="mt-1 block">{{ $t("Course") }}</strong>
        </button>
        <button type="button" class="rounded-xl border border-outline p-3 text-left hover:bg-raised" @click="openStep='participants'">
          <span class="text-xs font-bold uppercase tracking-wider" :class="participantsReady?'text-success':'text-warning'">{{ participantsReady?$t('Ready'):'2' }}</span>
          <strong class="mt-1 block">{{ $t("Athletes") }}</strong>
        </button>
        <button type="button" class="rounded-xl border border-outline p-3 text-left hover:bg-raised" @click="openStep='race-day'">
          <span class="text-xs font-bold uppercase tracking-wider" :class="readiness.ready_to_start?'text-success':'text-muted'">{{ readiness.ready_to_start?$t('Ready'):'3' }}</span>
          <strong class="mt-1 block">{{ $t("Start & timing") }}</strong>
        </button>
      </div>
    </section>

    <section v-if="isAdmin && !race.started_at" id="prepare" class="mb-4 scroll-mt-28 rounded-2xl border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left" :aria-expanded="openStep==='prepare'" @click="toggleStep('prepare')">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">{{ $t("Step 1") }}</p><h2 class="text-xl font-bold">{{ $t("Course") }}</h2><p class="mt-1 text-sm muted">{{ $t("The default triathlon checkpoints are ready to use. Only change them if this race needs something different.") }}</p></div>
        <i :class="openStep==='prepare'?'fa-solid fa-chevron-up':'fa-solid fa-chevron-down'" aria-hidden="true"></i>
      </button>

      <div v-show="openStep==='prepare'" class="border-t border-outline p-4">
        <div class="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
          <section class="rounded-2xl border border-outline p-4">
            <div v-if="!editingRaceDetails">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <h3 class="font-bold">{{ $t("Race details") }}</h3>
                  <p class="mt-2 text-sm muted">{{ raceForm.event_date }} · {{ raceForm.timezone }}</p>
                </div>
                <button type="button" class="btn-secondary !px-3" @click="editingRaceDetails=true"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>{{ $t("Edit details") }}</button>
              </div>
              <div class="mt-4 grid grid-cols-3 gap-2 text-center">
                <div class="rounded-xl bg-canvas p-3"><strong class="block">{{ raceForm.swim_km }} km</strong><span class="text-xs muted">{{ $t("Swim") }}</span></div>
                <div class="rounded-xl bg-canvas p-3"><strong class="block">{{ raceForm.bike_km }} km</strong><span class="text-xs muted">{{ $t("Bike") }}</span></div>
                <div class="rounded-xl bg-canvas p-3"><strong class="block">{{ raceForm.run_km }} km</strong><span class="text-xs muted">{{ $t("Run") }}</span></div>
              </div>
            </div>
            <form v-else @submit.prevent="saveRace">
              <div class="flex items-center justify-between gap-3"><h3 class="font-bold">{{ $t("Edit race details") }}</h3><button type="button" class="btn-icon" :aria-label="$t('Close race details')" @click="editingRaceDetails=false;raceForm.clearErrors()"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
              <div class="mt-4 space-y-4">
                <label class="label">{{ $t("Race name") }}<input v-model="raceForm.name" class="field" required></label>
                <label class="label">{{ $t("Date") }}<input v-model="raceForm.event_date" type="date" class="field" required></label>
                <fieldset><legend class="label">{{ $t("Distances") }}</legend><div class="grid gap-3 sm:grid-cols-3">
                  <label class="label">{{ $t("Swim (km)") }}<input v-model="raceForm.swim_km" class="field" type="number" min="0.001" step="0.001" required></label>
                  <label class="label">{{ $t("Bike (km)") }}<input v-model="raceForm.bike_km" class="field" type="number" min="0.001" step="0.001" required></label>
                  <label class="label">{{ $t("Run (km)") }}<input v-model="raceForm.run_km" class="field" type="number" min="0.001" step="0.001" required></label>
                </div></fieldset>
                <label class="label">{{ $t("Timezone") }}<input v-model="raceForm.timezone" class="field" required></label>
              </div>
              <p v-if="Object.keys(raceForm.errors).length" class="mt-3 text-sm text-error">{{ Object.values(raceForm.errors)[0] }}</p>
              <div class="mt-4 flex flex-wrap gap-2"><button class="btn-primary" :disabled="raceForm.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ $t("Save details") }}</button><button type="button" class="btn-secondary" @click="editingRaceDetails=false">{{ $t("Cancel") }}</button></div>
            </form>
          </section>

          <section class="rounded-2xl border border-outline p-4">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div><h3 class="font-bold">{{ $t("Checkpoints") }}</h3><p class="mt-1 text-sm muted">{{ $t("Anyone with the race-day link can choose any active checkpoint. Official accounts are optional.") }}</p></div>
              <button v-if="!checkpointFormOpen" class="btn-primary" type="button" @click="beginAddCheckpoint"><i class="fa-solid fa-plus" aria-hidden="true"></i>{{ $t("Add checkpoint") }}</button>
            </div>

            <p v-if="checkpointError" class="mt-3 text-sm text-error" role="alert">{{ checkpointError }}</p>
            <div class="mt-4 rounded-xl border border-outline bg-canvas p-3">
              <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
                <div>
                  <strong>{{ $t("Official accounts are optional") }}</strong>
                  <p class="mt-1 text-sm muted">{{ $t("Use them only when you want named accounts locked to specific checkpoints. The race-day link works without accounts or assignments.") }}</p>
                </div>
                <button type="button" class="btn-secondary shrink-0" @click="showOfficialTools=!showOfficialTools">
                  <i class="fa-solid fa-user-shield" aria-hidden="true"></i>{{ showOfficialTools?$t('Hide official setup'):$t('Set up officials') }}
                </button>
              </div>
            </div>
            <div class="mt-4 space-y-3">
              <article v-for="cp in setupCheckpoints" :key="cp.id" class="rounded-xl border border-outline p-3">
                <div class="flex flex-wrap items-start justify-between gap-3">
                  <div class="min-w-0">
                    <strong class="block">{{ cp.name }}</strong>
                    <span class="mt-1 block text-sm muted">{{ checkpointDistanceText(race, cp) }}</span>
                  </div>
                  <div class="flex flex-wrap gap-2">
                    <button class="btn-secondary !px-3" type="button" :aria-expanded="checkpointFormOpen && editingCheckpoint?.id===cp.id" @click="beginEditCheckpoint(cp)"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>{{ $t("Edit") }}</button>
                    <button v-if="showOfficialTools" class="btn-secondary !px-3" type="button" :aria-expanded="assigningCheckpoint?.id===cp.id" @click="beginAssignOfficial(cp)"><i class="fa-solid fa-user-plus" aria-hidden="true"></i>{{ $t("Assign account") }}</button>
                    <button class="btn-icon text-error" type="button" :aria-label="$t('Delete checkpoint')" :title="$t('Delete checkpoint')" @click="removingCheckpoint=cp"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                  </div>
                </div>

                <div v-if="showOfficialTools" class="mt-3 flex flex-wrap gap-2">
                  <span v-if="!assignmentsFor(cp.id).length" class="text-sm muted">{{ $t("No account assigned — that is fine for the race-day link") }}</span>
                  <span v-for="assignment in assignmentsFor(cp.id)" :key="assignment.id" class="inline-flex items-center gap-2 rounded-full bg-canvas px-3 py-2 text-sm">
                    <i class="fa-solid fa-user" aria-hidden="true"></i>{{ assignment.user.name }}
                    <button type="button" class="text-error" :aria-label="`Remove ${assignment.user.name}`" @click="removeOfficial(assignment.user)"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
                  </span>
                </div>

                <form v-if="checkpointFormOpen && editingCheckpoint?.id===cp.id" class="mt-4 rounded-2xl border border-cyan-400/30 bg-canvas p-4" @submit.prevent="saveCheckpoint">
                  <div class="flex items-center justify-between gap-3"><h4 class="font-bold">{{ $t("Edit checkpoint") }}</h4><button type="button" class="btn-icon" :aria-label="$t('Close checkpoint form')" @click="closeCheckpointForm"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
                  <div class="mt-4 grid gap-4 sm:grid-cols-2">
                    <div class="sm:col-span-2"><label class="label" :for="`checkpoint-name-${cp.id}`">{{ $t("Name") }}</label><input :id="`checkpoint-name-${cp.id}`" v-model="checkpoint.name" class="field" :placeholder="$t('For example: Run 4 km')" required></div>
                    <div><label class="label" :for="`checkpoint-sport-${cp.id}`">{{ $t("Sport") }}</label><select :id="`checkpoint-sport-${cp.id}`" v-model="checkpoint.discipline" class="field"><option value="">{{ $t("Race-wide") }}</option><option value="swim">{{ $t("Swim") }}</option><option value="bike">{{ $t("Bike") }}</option><option value="run">{{ $t("Run") }}</option></select></div>
                    <div><label class="label" :for="`checkpoint-kind-${cp.id}`">{{ $t("What happens here?") }}</label><select :id="`checkpoint-kind-${cp.id}`" v-model="checkpoint.kind" class="field"><option value="split">{{ $t("Timing point") }}</option><option value="transition">{{ $t("Transition") }}</option><option value="finish">{{ $t("Finish") }}</option></select></div>
                    <div class="sm:col-span-2"><label class="label" :for="`checkpoint-distance-${cp.id}`">{{ $t("Distance into this sport (km)") }} <span class="font-normal muted">{{ $t("(optional)") }}</span></label><input :id="`checkpoint-distance-${cp.id}`" v-model="checkpoint.distance_km" class="field" type="number" min="0" step="0.001"></div>
                  </div>
                  <p v-if="Object.keys(checkpoint.errors).length" class="mt-3 text-sm text-error">{{ Object.values(checkpoint.errors).join(' · ') }}</p>
                  <div class="mt-4 flex flex-wrap gap-2"><button class="btn-primary" :disabled="checkpoint.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ $t("Save checkpoint") }}</button><button type="button" class="btn-secondary" @click="closeCheckpointForm">{{ $t("Cancel") }}</button></div>
                </form>

                <form v-if="showOfficialTools && assigningCheckpoint?.id===cp.id" class="mt-4 rounded-2xl border border-cyan-400/30 bg-canvas p-4" @submit.prevent="assignOfficial">
                  <div class="flex items-center justify-between gap-3"><div><h4 class="font-bold">{{ $t("Optional Official account") }}</h4><p class="mt-1 text-sm muted">{{ cp.name }} · only needed if you want account-based access</p></div><button type="button" class="btn-icon" :aria-label="$t('Close official form')" @click="closeOfficialForm"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
                  <div class="mt-4 grid gap-3 sm:grid-cols-2">
                    <label class="label">{{ $t("Name") }}<input :value="officialForm.name" class="field" autocomplete="off" :placeholder="$t('Official name')" required @input="updateOfficialField('name',$event)"></label>
                    <label class="label">{{ $t("Email") }}<input :value="officialForm.email" type="email" class="field" autocomplete="off" :placeholder="$t('official@example.com')" required @input="updateOfficialField('email',$event)"></label>
                  </div>
                  <p class="mt-2 text-xs muted">{{ $t("Available Officials are shown below. Typing a name or email filters the list. If you do not select an existing account, a new Official is created from these details.") }}</p>

                  <div v-if="selectedOfficial" class="mt-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3">
                    <div class="flex items-start justify-between gap-3">
                      <div>
                        <span class="text-xs font-bold uppercase tracking-wider text-success">{{ $t("Existing Official selected") }}</span>
                        <strong class="mt-1 block">{{ selectedOfficial.name }}</strong>
                        <span class="mt-1 block text-sm muted">{{ selectedOfficial.email }}{{ selectedOfficial.role==='admin'?' · Organizer (admin)':'' }}</span>
                        <span v-if="assignmentForOfficial(selectedOfficial.id)" class="mt-1 block text-xs muted">Currently assigned to {{ assignmentForOfficial(selectedOfficial.id)?.checkpoint?.name }}</span>
                      </div>
                      <button type="button" class="btn-secondary !px-3" @click="clearOfficialSelection"><i class="fa-solid fa-rotate" aria-hidden="true"></i>{{ $t("Change") }}</button>
                    </div>
                  </div>

                  <div v-else-if="availableOfficials.length" class="mt-3 rounded-xl border border-cyan-400/30 bg-canvas p-2">
                    <div class="flex items-center justify-between gap-3 px-2 pb-2">
                      <p class="text-xs font-bold uppercase tracking-wider text-accent">{{ officialFilterTokens.length ? 'Matching officials' : $t('Available officials') }}</p>
                      <span class="text-xs muted">{{ availableOfficials.length }} shown</span>
                    </div>
                    <div class="max-h-60 space-y-1 overflow-y-auto pr-1">
                      <button v-for="official in availableOfficials" :key="official.id" type="button" class="w-full rounded-xl border border-outline p-3 text-left hover:border-cyan-400 hover:bg-raised" @click="selectOfficial(official)">
                        <span class="flex flex-wrap items-center justify-between gap-2">
                          <strong>{{ official.name }}</strong>
                          <span v-if="official.role==='admin'" class="badge">{{ $t("Organizer (admin)") }}</span>
                        </span>
                        <span class="mt-1 block text-sm muted">{{ official.email }}</span>
                        <span v-if="assignmentForOfficial(official.id)" class="mt-1 block text-xs muted">Assigned to {{ assignmentForOfficial(official.id)?.checkpoint?.name }}</span>
                      </button>
                    </div>
                  </div>

                  <p v-else class="mt-3 rounded-xl bg-canvas p-3 text-sm muted">{{ $t("No existing Official matches these details. Complete the form below to create and assign a new Official.") }}</p>

                  <div v-if="!officialForm.user_id && !availableOfficials.length" class="mt-4 space-y-3">
                    <label class="label">{{ $t("Account setup") }}<select v-model="officialForm.delivery" class="field"><option value="email" :disabled="!mailConfigured">{{ $t("Send password setup email") }}</option><option value="manual">{{ $t("Use a temporary password") }}</option></select></label>
                    <label v-if="officialForm.delivery==='manual'" class="label">{{ $t("Temporary password") }}<input v-model="officialForm.password" type="password" minlength="12" class="field" required></label>
                  </div>
                  <p class="mt-2 text-xs muted">{{ $t("Organizers (admin) are included in the Official list and can be assigned to a checkpoint.") }}</p>
                  <p v-if="Object.keys(officialForm.errors).length" class="mt-3 text-sm text-error">{{ Object.values(officialForm.errors)[0] }}</p>
                  <button v-if="officialForm.user_id || !availableOfficials.length" class="btn-primary mt-4" :disabled="officialForm.processing"><i class="fa-solid fa-user-check" aria-hidden="true"></i>{{ officialForm.user_id?$t('Assign to checkpoint'):$t('Create & assign Official') }}</button>
                  <p v-else class="mt-4 rounded-xl bg-canvas p-3 text-sm muted">{{ $t("Select an Official from the list, or keep typing the name and email until there is no existing match to create a new account.") }}</p>
                </form>
              </article>
            </div>

            <form v-if="checkpointFormOpen && !editingCheckpoint" class="mt-4 rounded-2xl border border-cyan-400/30 bg-canvas p-4" @submit.prevent="saveCheckpoint">
              <div class="flex items-center justify-between gap-3"><h4 class="font-bold">{{ $t("Add checkpoint") }}</h4><button type="button" class="btn-icon" :aria-label="$t('Close checkpoint form')" @click="closeCheckpointForm"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button></div>
              <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2"><label class="label" for="checkpoint-add-name">{{ $t("Name") }}</label><input id="checkpoint-add-name" v-model="checkpoint.name" class="field" :placeholder="$t('For example: Run 4 km')" required></div>
                <div><label class="label" for="checkpoint-add-sport">{{ $t("Sport") }}</label><select id="checkpoint-add-sport" v-model="checkpoint.discipline" class="field"><option value="">{{ $t("Race-wide") }}</option><option value="swim">{{ $t("Swim") }}</option><option value="bike">{{ $t("Bike") }}</option><option value="run">{{ $t("Run") }}</option></select></div>
                <div><label class="label" for="checkpoint-add-kind">{{ $t("What happens here?") }}</label><select id="checkpoint-add-kind" v-model="checkpoint.kind" class="field"><option value="split">{{ $t("Timing point") }}</option><option value="transition">{{ $t("Transition") }}</option><option value="finish">{{ $t("Finish") }}</option></select></div>
                <div class="sm:col-span-2"><label class="label" for="checkpoint-add-distance">{{ $t("Distance into this sport (km)") }} <span class="font-normal muted">{{ $t("(optional)") }}</span></label><input id="checkpoint-add-distance" v-model="checkpoint.distance_km" class="field" type="number" min="0" step="0.001"></div>
              </div>
              <p v-if="Object.keys(checkpoint.errors).length" class="mt-3 text-sm text-error">{{ Object.values(checkpoint.errors).join(' · ') }}</p>
              <div class="mt-4 flex flex-wrap gap-2"><button class="btn-primary" :disabled="checkpoint.processing"><i class="fa-solid fa-plus" aria-hidden="true"></i>{{ $t("Add checkpoint") }}</button><button type="button" class="btn-secondary" @click="closeCheckpointForm">{{ $t("Cancel") }}</button></div>
            </form>
          </section>
        </div>
      </div>
    </section>

    <section v-if="isAdmin && !race.started_at" id="participants" class="mb-4 scroll-mt-28 rounded-2xl border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left" :aria-expanded="openStep==='participants'" @click="toggleStep('participants')">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">{{ $t("Step 2") }}</p><h2 class="text-xl font-bold">{{ $t("Athletes") }}</h2><p class="mt-1 text-sm muted">{{ race.entries_count ?? 0 }} added</p></div>
        <i :class="openStep==='participants'?'fa-solid fa-chevron-up':'fa-solid fa-chevron-down'" aria-hidden="true"></i>
      </button>
      <div v-show="openStep==='participants'" class="border-t border-outline p-4">
        <div class="grid gap-5 xl:grid-cols-[.9fr_1.1fr]">
          <form class="rounded-2xl border border-outline p-4" @submit.prevent="addParticipant">
            <h3 class="font-bold">{{ $t("Add athlete") }}</h3>
            <p class="mt-1 text-sm muted">{{ $t("For a normal solo athlete, just enter the name. Bibs and everything else are optional.") }}</p>

            <label class="label mt-4">{{ $t("Bib number") }} <span class="font-normal muted">{{ $t("(optional)") }}</span>
              <input v-model="participantForm.bib_number" class="field" maxlength="32" :placeholder="$t('Leave blank if you do not use bibs')">
            </label>

            <details class="mt-4 rounded-xl border border-outline p-3">
              <summary class="cursor-pointer text-sm font-semibold">{{ $t("Relay, category & other race options") }}</summary>
              <div class="mt-3 grid gap-3">
                <label class="label">{{ $t("Entry type") }}
                  <select v-model="participantForm.type" class="field"><option value="solo">{{ $t("Solo athlete") }}</option><option value="relay">{{ $t("3-person relay") }}</option></select>
                </label>
                <label v-if="participantForm.type==='relay'" class="label">{{ $t("Team name") }}<input v-model="participantForm.team_name" class="field" required></label>
                <label class="label">{{ $t("Category") }} <span class="font-normal muted">{{ $t("(optional)") }}</span><input v-model="participantForm.category" class="field"></label>
              </div>
            </details>

            <div class="mt-4 space-y-3">
              <RaceAthletePicker
                v-for="(member,index) in participantForm.members"
                :key="member.discipline"
                v-model="participantForm.members[index]"
                :race-id="race.id"
                :bib-number="participantForm.bib_number"
                :initial-options="athleteOptions"
                :title="participantForm.type==='solo'?$t('Athlete name'):disciplineLabel(member.discipline)"
              />
            </div>
            <p v-if="Object.keys(participantForm.errors).length" class="mt-3 text-sm text-error">{{ Object.values(participantForm.errors)[0] }}</p>
            <div class="mt-4 flex flex-wrap gap-2">
              <button class="btn-primary" :disabled="participantForm.processing"><i class="fa-solid fa-user-plus" aria-hidden="true"></i>{{ $t("Add athlete") }}</button>
              <Link :href="`/races/${race.id}/participants/import`" class="btn-secondary"><i class="fa-solid fa-file-import" aria-hidden="true"></i>{{ $t("Import file") }}</Link>
            </div>
          </form>

          <section class="rounded-2xl border border-outline overflow-hidden">
            <div class="border-b border-outline p-4"><h3 class="font-bold">{{ $t("Added athletes & teams") }}</h3></div>
            <div v-if="participants.length" class="divide-y divide-outline">
              <div v-for="participant in participants.slice(0,10)" :key="participant.id" class="flex items-center gap-3 p-4">
                <span class="max-w-24 shrink-0 rounded-xl bg-raised px-3 py-2 font-mono font-bold">{{ bibLabel(participant.bib_number) }}</span>
                <div class="min-w-0 flex-1"><strong class="block truncate">{{ participant.name }}</strong><span class="text-sm muted">{{ participant.type==='relay'?$t('Relay team'):$t('Solo athlete') }}</span></div>
                <Link :href="`/races/${race.id}/participants/${participant.id}/edit`" class="btn-secondary"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>{{ $t("Edit") }}</Link>
              </div>
            </div>
            <div v-else class="p-6 text-center muted">{{ $t("No athletes or teams yet.") }}</div>
            <div v-if="participants.length>10" class="border-t border-outline p-4"><Link :href="`/races/${race.id}/participants`" class="font-semibold text-accent underline">View all {{ participants.length }}</Link></div>
          </section>
        </div>
      </div>
    </section>

    <section id="race-day" class="mb-4 scroll-mt-28 rounded-2xl border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left" :aria-expanded="openStep==='race-day'" @click="toggleStep('race-day')">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">{{ race.started_at?'Live':$t('Step 3') }}</p><h2 class="text-xl font-bold">{{ race.started_at?$t('Timing'):$t('Start & timing') }}</h2><p class="mt-1 text-sm muted">{{ $t("As Organizer, you can switch between checkpoints while timing.") }}</p></div>
        <i :class="openStep==='race-day'?'fa-solid fa-chevron-up':'fa-solid fa-chevron-down'" aria-hidden="true"></i>
      </button>

      <div v-show="openStep==='race-day'" class="border-t border-outline p-4">
        <section v-if="isAdmin && raceDayLink" class="mb-4 rounded-2xl border border-cyan-400/30 bg-cyan-400/5 p-4">
          <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <div class="text-xs font-bold uppercase tracking-[.18em] text-accent">{{ $t("Race-day link") }}</div>
              <h3 class="mt-1 font-bold">{{ $t("No accounts or checkpoint assignments needed") }}</h3>
              <p class="mt-1 text-sm muted">{{ $t("Share this link with anyone helping at the race. They choose the transition or finish and tap the correct athlete to record the time.") }}</p>
            </div>
            <div class="flex shrink-0 flex-wrap gap-2">
              <a :href="raceDayLink" target="_blank" rel="noopener" class="btn-primary"><i class="fa-solid fa-arrow-up-right-from-square" aria-hidden="true"></i>{{ $t("Open race-day view") }}</a>
              <button type="button" class="btn-secondary" @click="copyRaceDayLink"><i class="fa-solid fa-link" aria-hidden="true"></i>{{ copiedRaceDayLink?$t('Copied'):$t('Copy link') }}</button>
            </div>
          </div>
        </section>

        <section v-if="!race.started_at" class="mb-4 grid gap-2 sm:grid-cols-2 lg:grid-cols-3" :aria-label="$t('Race readiness')">
          <div v-for="check in visibleReadinessChecks" :key="check.key" class="rounded-xl border border-outline p-3">
            <div class="flex items-center gap-2"><span :class="check.ready?'text-success':check.required?'text-error':'text-warning'">{{ check.ready?'✓':'!' }}</span><strong class="text-sm">{{ check.label }}</strong><span v-if="!check.required" class="ml-auto text-[10px] uppercase tracking-wider muted">{{ $t("Recommended") }}</span></div>
            <p class="mt-1 text-xs muted">{{ check.detail }}</p>
          </div>
        </section>
        <section class="mb-5 rounded-2xl bg-canvas p-4">
          <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
            <div><div class="mb-1 text-xs font-bold uppercase tracking-[.18em] text-accent">{{ $t("Race clock") }}</div><RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow"/><div class="mt-2"><LiveUpdatesStatus :race-id="race.id"/></div></div>
            <div v-if="isAdmin" class="flex flex-wrap gap-2">
              <button v-if="!race.started_at" class="btn-primary min-w-36" :disabled="!readiness.ready_to_start" @click="pendingClockAction='start'"><i class="fa-solid fa-play" aria-hidden="true"></i>{{ $t("Start race") }}</button>
              <button v-else-if="!race.finished_at" class="btn-danger" @click="pendingClockAction='finish'"><i class="fa-solid fa-stop" aria-hidden="true"></i>{{ $t("End race now") }}</button>
              <span v-else class="badge">{{ $t("Race finished") }}</span>
            </div>
          </div>
          <p v-if="!race.started_at && !readiness.ready_to_start" class="mt-4 text-sm text-warning">{{ $t("Finish the required items above before starting the race.") }}</p>
          <p v-if="race.started_at && !race.finished_at" class="mt-4 text-sm muted">{{ $t("The race will end automatically when every active participant has a finish time. The Organizer can end it manually if needed.") }}</p>
        </section>

        <div v-if="can('timings.record')" class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
          <section class="panel overflow-hidden">
            <div class="border-b border-outline p-4">
              <div v-if="selectedCheckpoint" class="grid gap-3" :class="isAdmin?'sm:grid-cols-[minmax(0,1fr)_minmax(220px,.6fr)]':''">
                <label class="label">{{ $t("Find athlete or team") }}<input v-model="timingSearch" class="field min-h-14 text-lg" :placeholder="$t('Bib number or name')"></label>
                <label v-if="isAdmin" class="label">{{ $t("Checkpoint") }}<select v-model="selectedCheckpointId" class="field min-h-14"><option v-for="cp in activeTimingCheckpoints" :key="cp.id" :value="cp.id">{{ cp.name }}</option></select></label>
                <div v-else class="rounded-xl bg-canvas p-3"><span class="text-xs font-bold uppercase tracking-wider text-accent">{{ $t("Assigned checkpoint") }}</span><strong class="mt-1 block">{{ selectedCheckpoint.name }}</strong></div>
              </div>
              <div v-else class="rounded-xl bg-amber-500/10 p-4 text-warning"><strong>{{ $t("No checkpoint assigned.") }}</strong><span class="mt-1 block text-sm">{{ $t("Ask the Organizer to assign this account before race day.") }}</span></div>
              <p v-if="timingFeedback" class="mt-3 rounded-xl p-3 text-sm font-semibold" :class="timingFeedback.type==='ok'?'bg-emerald-500/10 text-success':timingFeedback.type==='offline'?'bg-amber-500/10 text-warning':'bg-red-500/10 text-error'" role="status">{{ timingFeedback.message }}</p><p v-if="workspacePending" class="mt-2 text-sm text-warning"><i class="fa-solid fa-cloud-arrow-up mr-1" aria-hidden="true"></i>{{ workspacePending }} timing{{ workspacePending===1?'':'s' }} waiting to upload automatically. <Link :href="`/races/${race.id}/station`" class="font-semibold underline">{{ $t("Review device sync") }}</Link></p>
            </div>

            <div v-if="race.started_at && !race.finished_at && selectedCheckpoint" class="max-h-[58dvh] overflow-y-auto p-2">
              <button
                v-for="participant in filteredTimingParticipants"
                :key="participant.id"
                class="mb-2 grid min-h-20 w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 rounded-2xl border p-4 text-left transition"
                :class="participantRecorded(participant)?'border-emerald-500/30 bg-emerald-500/10 opacity-70':'border-outline bg-surface hover:border-cyan-400 hover:bg-raised active:scale-[.995]'"
                :disabled="participantRecorded(participant) || timingSaving.has(participant.id) || (!!participant.status && participant.status!=='registered')"
                @click="recordTiming(participant)"
              >
                <span class="max-w-24 rounded-xl bg-canvas px-3 py-2 font-mono text-xl font-black">{{ bibLabel(participant.bib_number) }}</span>
                <span class="min-w-0"><strong class="block truncate text-lg">{{ participant.name }}</strong><span class="block truncate text-sm muted">{{ participant.type==='relay'?participant.members.map(member=>`${disciplineLabel(member.discipline)}: ${member.name}`).join(' · '):$t('Solo athlete') }}</span></span>
                <span class="text-sm font-bold" :class="participantRecorded(participant)?'text-success':'text-accent'">{{ participant.status && participant.status!=='registered'?participant.status.toUpperCase():timingSaving.has(participant.id)?$t('SAVING'):participantRecorded(participant)?$t('RECORDED'):$t('TAP') }}</span>
              </button>
              <p v-if="!filteredTimingParticipants.length" class="p-8 text-center muted">{{ $t("No matching athlete.") }}</p>
            </div>
            <div v-else-if="selectedCheckpoint" class="p-8 text-center"><strong>{{ race.finished_at?$t('Timing is closed'):'Timing opens when the race starts' }}</strong></div>
          </section>

          <section class="panel overflow-hidden">
            <div class="border-b border-outline p-4"><div class="flex items-center justify-between gap-3"><h3 class="font-bold">{{ $t("Latest times") }}</h3><span class="badge">{{ localCompletedCount }} finished</span></div></div>
            <div v-if="recent.length" class="divide-y divide-outline">
              <div v-for="timing in recent.slice(0,12)" :key="timing.id ?? timing.client_uuid" class="p-4">
                <div class="flex items-center justify-between gap-3">
                  <div class="min-w-0"><strong class="block truncate">{{ timing.entry?.display_name ?? bibLabel(timing.entry?.bib_number) }}</strong><span class="text-sm muted">{{ timing.checkpoint?.name }}<span v-if="timing.operator?.name"> · {{ timing.operator.name }}</span></span></div>
                  <span class="shrink-0 font-mono font-bold">{{ formatDuration(timing.elapsed_ms,2) }}</span>
                </div>
              </div>
            </div>
            <div v-else class="p-6 text-center muted">{{ $t("No times yet.") }}</div>
          </section>
        </div>

        <section v-else class="panel-pad"><p class="font-semibold">{{ $t("Your account cannot record timings.") }}</p></section>
      </div>
    </section>

    <section id="finish" class="mb-4 scroll-mt-28 rounded-2xl border border-outline bg-surface">
      <button type="button" class="flex w-full items-center justify-between gap-4 p-4 text-left" :aria-expanded="openStep==='finish'" @click="toggleStep('finish')">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">{{ race.finished_at?'Finished':$t('Step 4') }}</p><h2 class="text-xl font-bold">{{ $t("Results") }}</h2><p class="mt-1 text-sm muted">{{ localCompletedCount }} of {{ race.entries_count ?? 0 }} have a finish time</p></div>
        <i :class="openStep==='finish'?'fa-solid fa-chevron-up':'fa-solid fa-chevron-down'" aria-hidden="true"></i>
      </button>
      <div v-show="openStep==='finish'" class="border-t border-outline p-4">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div><h3 class="font-bold">{{ race.finished_at?$t('Race complete'):$t('Results update while the race is live') }}</h3><p class="mt-1 muted">{{ $t("Open the results table for places and split times.") }}</p></div>
          <Link :href="`/races/${race.id}/results`" class="btn-primary"><i class="fa-solid fa-trophy" aria-hidden="true"></i>{{ $t("View results") }}</Link>
        </div>

        <div v-if="isAdmin && race.finished_at" class="mt-5 border-t border-outline pt-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h3 class="font-bold">{{ $t("Public results") }}</h3><p class="mt-1 text-sm muted">{{ race.results_published_at?$t('Published'):$t('Private') }}</p></div>
            <button class="btn-secondary" @click="publishing=true"><i :class="race.results_published_at?'fa-solid fa-eye-slash':'fa-solid fa-globe'" aria-hidden="true"></i>{{ race.results_published_at?$t('Unpublish'):$t('Publish results') }}</button>
          </div>
          <a v-if="race.results_published_at && race.public_results_token" :href="`/live/${race.public_results_token}`" target="_blank" rel="noopener" class="mt-3 inline-block font-semibold text-accent underline">{{ $t("Open public leaderboard ↗") }}</a>
        </div>
      </div>
    </section>

    <details class="panel-pad">
      <summary class="cursor-pointer font-bold"><i class="fa-solid fa-toolbox mr-2" aria-hidden="true"></i>{{ $t("More tools") }}</summary>
      <p class="mt-2 text-sm muted">{{ $t("Only use these when the normal race flow is not enough.") }}</p>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <Link v-if="can('timings.record')" :href="`/races/${race.id}/station`" class="rounded-xl border border-outline p-4 hover:bg-raised"><strong><i class="fa-solid fa-stopwatch mr-2" aria-hidden="true"></i>{{ $t("Full-screen timing station") }}</strong><p class="mt-2 text-sm muted">{{ $t("The same race timing workflow with extra offline and device recovery controls.") }}</p></Link>
        <Link v-if="isAdmin && !race.started_at" :href="`/races/${race.id}/participants`" class="rounded-xl border border-outline p-4 hover:bg-raised"><strong><i class="fa-solid fa-users mr-2" aria-hidden="true"></i>{{ $t("Full participant list") }}</strong><p class="mt-2 text-sm muted">{{ $t("Search or edit registration details before the start.") }}</p></Link>
        <Link v-if="isAdmin && race.started_at" :href="`/races/${race.id}/control`" class="rounded-xl border border-outline p-4 hover:bg-raised"><strong><i class="fa-solid fa-screwdriver-wrench mr-2" aria-hidden="true"></i>{{ $t("Corrections & station health") }}</strong><p class="mt-2 text-sm muted">{{ $t("Correct recorded times or check which timing stations are online.") }}</p></Link>
      </div>
      <div v-if="isAdmin && !race.started_at" class="mt-5 border-t border-red-500/20 pt-5"><button class="btn-danger" @click="deleting=true"><i class="fa-solid fa-trash" aria-hidden="true"></i>{{ $t("Delete race") }}</button></div>
    </details>

    <ConfirmDialog
      v-if="pendingClockAction"
      :title="pendingClockAction==='start'?$t('Start the race?'):$t('End the race now?')"
      :message="pendingClockAction==='start'?$t('Start the shared clock now? Timing opens immediately for every checkpoint.'):'Stop the race now? Normally it ends automatically when the last active athlete finishes.'"
      :confirm-label="pendingClockAction==='start'?$t('Start race'):'End race'"
      :busy="clockForm.processing"
      @cancel="pendingClockAction=null; clockForm.clearErrors()"
      @confirm="changeClock"
    >
      <p v-if="Object.keys(clockForm.errors).length" class="mt-3 text-error">{{ Object.values(clockForm.errors)[0] }}</p>
    </ConfirmDialog>

    <ConfirmDialog
      v-if="timingConfirmation"
      :title="$t('Record this time?')"
      :message="timingConfirmation.message"
      confirm-label="Record anyway"
      @cancel="timingConfirmation=null"
      @confirm="confirmTimingOverride"
    />

    <ConfirmDialog
      v-if="removingCheckpoint"
      :title="$t('Delete checkpoint?')"
      :message="`Delete ${removingCheckpoint.name}?`"
      confirm-label="Delete checkpoint"
      @cancel="removingCheckpoint=null"
      @confirm="confirmCheckpointRemoval"
    />

    <ConfirmDialog
      v-if="publishing"
      :title="race.results_published_at?$t('Unpublish results?'):$t('Publish results?')"
      :message="race.results_published_at?$t('The public link will stop working.'):$t('Anyone with the link will be able to view participant names and results.')"
      :confirm-label="race.results_published_at?$t('Unpublish'):$t('Publish results')"
      :busy="publication.processing"
      @cancel="publishing=false"
      @confirm="publish"
    />

    <ConfirmDialog
      v-if="deleting"
      :title="$t('Delete race?')"
      :message="`Move ${race.name} to Deleted races?`"
      confirm-label="Delete race"
      :busy="deleteForm.processing"
      @cancel="deleting=false"
      @confirm="deleteRace"
    />
  </AppLayout>
</template>
