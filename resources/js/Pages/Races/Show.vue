<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import RaceClock from '../../Components/RaceClock.vue';
import LiveUpdatesStatus from '../../Components/LiveUpdatesStatus.vue';
import { usePermissions } from '../../Composables/usePermissions';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { checkpointDistanceText } from '../../checkpointDistance';
import { bibLabel, formatDuration, jsonRequest, uuid } from '../../lib';
import type { Checkpoint, PageProps, Race, StationParticipant } from '../../types';

interface Organizer {
  id: number;
  name: string;
  email: string;
  is_active?: boolean;
}

interface Timing {
  id?: number;
  client_uuid?: string;
  elapsed_ms: number;
  recorded_at: string;
  entry?: { id:number; bib_number:string|null; display_name?:string };
  checkpoint?: { id?:number; name:string };
  operator?: { name:string };
}

const props = defineProps<{
  race: Race & { checkpoints: Checkpoint[]; organizers: Organizer[]; entries_count?: number };
  organizers: Organizer[];
  participants: StationParticipant[];
  recentTimings: Timing[];
  completedCount: number;
  serverNow: string;
}>();

const can = usePermissions();
const page = usePage<PageProps>();
const isAdmin = computed(() => page.props.auth.user?.role === 'admin');

useRaceRefresh(() => ['race', 'participants', 'recentTimings', 'completedCount', 'serverNow']);

const orderedCheckpoints = computed(() => [...props.race.checkpoints].sort((a,b) => a.sequence-b.sequence));
const activeTimingCheckpoints = computed(() => orderedCheckpoints.value.filter(cp => cp.is_active && cp.kind !== 'start'));
const hasFinish = computed(() => orderedCheckpoints.value.some(cp => cp.is_active && cp.kind === 'finish'));
const distancesReady = computed(() => ['swim_km','bike_km','run_km'].every(key => Number(props.race.settings?.[key] ?? 0) > 0));
const setupReady = computed(() => hasFinish.value && distancesReady.value);
const participantsReady = computed(() => (props.race.entries_count ?? 0) > 0);
const workflowState = computed(() => {
  if (props.race.finished_at) return { title:'Race finished', detail:'Review the results and only use correction tools if something needs fixing.', icon:'fa-solid fa-flag-checkered' };
  if (props.race.started_at) return { title:'Race is live', detail:'Choose a checkpoint and record participants as they pass. The shared clock is running.', icon:'fa-solid fa-stopwatch' };
  if (!setupReady.value) return { title:'Next: review the course', detail:'Check the race details and checkpoints below.', icon:'fa-solid fa-route' };
  if (!participantsReady.value) return { title:'Next: add participants', detail:'Add athletes or relay teams before race day.', icon:'fa-solid fa-users' };
  return { title:'Ready for race day', detail:'Everything essential is in place. Start the shared clock when the event begins.', icon:'fa-solid fa-play' };
});

const raceForm = useForm({
  name: props.race.name,
  event_date: props.race.event_date,
  timezone: props.race.timezone,
  status: props.race.status,
  swim_km: Number(props.race.settings?.swim_km ?? 1),
  bike_km: Number(props.race.settings?.bike_km ?? 35),
  run_km: Number(props.race.settings?.run_km ?? 8),
  auto_finish: Boolean(props.race.settings?.auto_finish ?? true),
  organizer_ids: props.race.organizers.map(organizer => organizer.id),
});
const saveRace = () => raceForm.put(`/races/${props.race.id}`, { preserveScroll:true });

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
  kind: 'split',
  distance_km: '',
  is_required: true,
  is_active: true,
});
const beginAddCheckpoint = () => {
  editingCheckpoint.value = null;
  checkpoint.clearErrors();
  checkpoint.name = '';
  checkpoint.code = '';
  checkpoint.sequence = suggestedSequence();
  checkpoint.discipline = 'run';
  checkpoint.kind = 'split';
  checkpoint.distance_km = '';
  checkpoint.is_required = true;
  checkpoint.is_active = true;
  checkpointFormOpen.value = true;
};
const beginEditCheckpoint = (cp: Checkpoint) => {
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
const checkpointError = ref('');
const removingCheckpoint = ref<Checkpoint|null>(null);
const toggleCheckpoint = (cp: Checkpoint) => router.put(`/races/${props.race.id}/checkpoints/${cp.id}`, {
  name: cp.name,
  code: cp.code,
  sequence: cp.sequence,
  discipline: cp.discipline,
  kind: cp.kind,
  distance_km: cp.distance_km,
  is_required: cp.is_required,
  is_active: !cp.is_active,
}, { preserveScroll:true, onError:errors => { checkpointError.value = String(Object.values(errors)[0] ?? 'Unable to update checkpoint'); } });
const confirmCheckpointRemoval = () => {
  if (!removingCheckpoint.value) return;
  router.delete(`/races/${props.race.id}/checkpoints/${removingCheckpoint.value.id}`, {
    preserveScroll:true,
    onSuccess:() => { removingCheckpoint.value = null; checkpointError.value = ''; },
    onError:errors => { checkpointError.value = String(Object.values(errors)[0] ?? 'Unable to delete checkpoint'); },
  });
};

const participantForm = useForm({
  bib_number: '',
  type: 'solo' as 'solo'|'relay',
  team_name: '',
  category: '',
  members: [{ discipline:'swim', first_name:'', last_name:'', email:'', club:'' }],
});
watch(() => participantForm.type, type => {
  participantForm.members = type === 'solo'
    ? [{ discipline:'swim', first_name:'', last_name:'', email:'', club:'' }]
    : ['swim','bike','run'].map(discipline => ({ discipline, first_name:'', last_name:'', email:'', club:'' }));
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
const participantRecorded = (participant:StationParticipant) => !!selectedCheckpoint.value && participant.completed_checkpoint_ids.includes(selectedCheckpoint.value.id);
const timingSaving = ref(new Set<number>());
const timingFeedback = ref<{type:'ok'|'error';message:string}|null>(null);
const timingConfirmation = ref<{participant:StationParticipant;clientUuid:string;message:string}|null>(null);
const showTimingFeedback = (type:'ok'|'error', message:string) => {
  timingFeedback.value = {type,message};
  window.setTimeout(() => { if (timingFeedback.value?.message === message) timingFeedback.value = null; }, 4500);
};

async function recordTiming(participant: StationParticipant, override = false, clientUuid = uuid()) {
  if (!selectedCheckpoint.value || timingSaving.value.has(participant.id)) return;
  if (!props.race.started_at) { showTimingFeedback('error','Start the race before recording checkpoint times.'); return; }
  if (props.race.finished_at) { showTimingFeedback('error','The race is finished. Use correction tools for changes.'); return; }
  if (participant.status && participant.status !== 'registered') { showTimingFeedback('error',`${participant.name} is marked ${participant.status.toUpperCase()}.`); return; }
  if (participantRecorded(participant)) { showTimingFeedback('error',`${participant.name} is already recorded at this checkpoint.`); return; }

  timingSaving.value.add(participant.id);
  try {
    const {response,data} = await jsonRequest<{
      timing?: Timing;
      message?: string;
      warning?: boolean;
      missing_checkpoints?: string[];
      auto_finished?: boolean;
    }>(`/races/${props.race.id}/timings`, {
      method:'POST',
      body:JSON.stringify({
        entry_id:participant.id,
        checkpoint_id:selectedCheckpoint.value.id,
        client_uuid:clientUuid,
        observed_at:new Date().toISOString(),
        source:'online',
        workspace:true,
        override_warning:override,
      }),
    });

    if (response.ok && data.timing) {
      participant.completed_checkpoint_ids.push(selectedCheckpoint.value.id);
      recent.value.unshift(data.timing);
      recent.value = recent.value.slice(0, 12);
      if (selectedCheckpoint.value.kind === 'finish') localCompletedCount.value += 1;
      showTimingFeedback('ok', data.message ?? 'Time recorded.');
      timingSearch.value = '';
      if (data.auto_finished) router.reload({only:['race','serverNow','completedCount']});
      return;
    }

    if (response.status === 409 && data.warning) {
      timingConfirmation.value = {
        participant,
        clientUuid,
        message:`${data.message ?? 'An earlier checkpoint is missing.'} ${(data.missing_checkpoints ?? []).join(', ')} Record anyway?`,
      };
      return;
    }

    showTimingFeedback('error', data.message ?? 'The time could not be recorded.');
  } catch {
    showTimingFeedback('error','The time could not be saved from this page. Use Focused timing mode if the connection is unreliable.');
  } finally {
    timingSaving.value.delete(participant.id);
  }
}
const confirmTimingOverride = () => {
  const item = timingConfirmation.value;
  timingConfirmation.value = null;
  if (item) void recordTiming(item.participant, true, item.clientUuid);
};

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
        <Link href="/guide" class="btn-secondary self-start lg:self-auto"><i class="fa-solid fa-circle-question" aria-hidden="true"></i>How this works</Link>
      </div>
      <div class="mt-5 grid gap-2 sm:grid-cols-4" aria-label="Race workflow">
        <a href="#prepare" class="rounded-xl border border-outline p-3 hover:bg-raised">
          <span class="text-xs font-bold uppercase tracking-wider" :class="setupReady?'text-success':'text-warning'">{{ setupReady?'Ready':'Step 1' }}</span>
          <strong class="mt-1 block">Prepare</strong>
        </a>
        <a href="#participants" class="rounded-xl border border-outline p-3 hover:bg-raised">
          <span class="text-xs font-bold uppercase tracking-wider" :class="participantsReady?'text-success':'text-warning'">{{ participantsReady?'Ready':'Step 2' }}</span>
          <strong class="mt-1 block">Participants</strong>
        </a>
        <a href="#race-day" class="rounded-xl border border-outline p-3 hover:bg-raised">
          <span class="text-xs font-bold uppercase tracking-wider" :class="race.started_at?'text-success':'text-muted'">{{ race.started_at?'Started':'Step 3' }}</span>
          <strong class="mt-1 block">Race day</strong>
        </a>
        <a href="#finish" class="rounded-xl border border-outline p-3 hover:bg-raised">
          <span class="text-xs font-bold uppercase tracking-wider" :class="race.finished_at?'text-success':'text-muted'">{{ race.finished_at?'Finished':'Step 4' }}</span>
          <strong class="mt-1 block">Finish</strong>
        </a>
      </div>
    </section>

    <section id="prepare" class="mb-6 scroll-mt-32">
      <div class="mb-3 flex items-center justify-between gap-3">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">Step 1</p><h2 class="text-xl font-bold">Prepare the race</h2></div>
        <span v-if="setupReady" class="badge">Ready</span>
      </div>

      <div class="grid gap-5 xl:grid-cols-[.85fr_1.15fr]">
        <form v-if="can('races.setup')" class="panel-pad" @submit.prevent="saveRace">
          <h3 class="text-lg font-bold">Race details</h3>
          <p class="mt-1 text-sm muted">Most races only need these fields. Less common options stay tucked away.</p>
          <div class="mt-4 space-y-4">
            <div><label for="race-name" class="label">Race name</label><input id="race-name" v-model="raceForm.name" class="field" required></div>
            <div class="grid gap-4 sm:grid-cols-2">
              <div><label for="race-date" class="label">Date</label><input id="race-date" v-model="raceForm.event_date" type="date" class="field" required></div>
              <div><label for="race-timezone" class="label">Timezone</label><input id="race-timezone" v-model="raceForm.timezone" class="field" required></div>
            </div>
            <fieldset>
              <legend class="label">Course distances</legend>
              <div class="grid gap-3 sm:grid-cols-3">
                <label class="label">Swim (km)<input v-model="raceForm.swim_km" class="field" type="number" min="0.001" step="0.001" :disabled="!!race.started_at" required></label>
                <label class="label">Bike (km)<input v-model="raceForm.bike_km" class="field" type="number" min="0.001" step="0.001" :disabled="!!race.started_at" required></label>
                <label class="label">Run (km)<input v-model="raceForm.run_km" class="field" type="number" min="0.001" step="0.001" :disabled="!!race.started_at" required></label>
              </div>
            </fieldset>
            <label class="flex gap-3 rounded-xl bg-canvas p-3">
              <input v-model="raceForm.auto_finish" type="checkbox">
              <span><strong>Finish automatically</strong><span class="mt-1 block text-sm muted">When every active participant has a finish time, stop the shared clock automatically. You can still finish manually.</span></span>
            </label>
            <details v-if="organizers.length">
              <summary class="cursor-pointer font-semibold">Officials assigned to this race</summary>
              <div class="mt-3 grid gap-2 rounded-xl border border-outline p-3">
                <label v-for="organizer in organizers" :key="organizer.id" class="flex gap-2">
                  <input v-model="raceForm.organizer_ids" type="checkbox" :value="organizer.id">
                  <span>{{ organizer.name }} <span class="muted">{{ organizer.email }}</span></span>
                </label>
              </div>
            </details>
          </div>
          <p v-if="Object.keys(raceForm.errors).length" class="mt-3 text-sm text-error">{{ Object.values(raceForm.errors)[0] }}</p>
          <button class="btn-primary mt-5" :disabled="raceForm.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>Save race details</button>
        </form>

        <section v-else class="panel-pad">
          <h3 class="text-lg font-bold">Race details</h3>
          <p class="mt-3">{{ race.event_date }} · {{ race.timezone }}</p>
          <p class="mt-2 muted">Swim {{ race.settings?.swim_km }} km · Bike {{ race.settings?.bike_km }} km · Run {{ race.settings?.run_km }} km</p>
        </section>

        <section class="panel-pad">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="text-lg font-bold">Checkpoints</h3>
              <p class="mt-1 text-sm muted">These are the places where an Official can record a participant.</p>
            </div>
            <button v-if="can('races.setup') && !race.started_at" class="btn-primary" type="button" @click="beginAddCheckpoint">
              <i class="fa-solid fa-plus" aria-hidden="true"></i>Add checkpoint
            </button>
          </div>

          <p v-if="checkpointError" class="mt-3 text-sm text-error" role="alert">{{ checkpointError }}</p>
          <div class="mt-4 space-y-2">
            <article v-for="cp in orderedCheckpoints" :key="cp.id" class="rounded-xl border border-outline p-3">
              <div class="flex flex-wrap items-start justify-between gap-3">
                <div>
                  <div class="font-semibold" :data-discipline="cp.discipline">{{ cp.name }}</div>
                  <div class="mt-1 text-sm muted">{{ checkpointDistanceText(race, cp) }}</div>
                  <div class="mt-1 text-xs muted">{{ cp.kind==='finish'?'Race finish':cp.kind==='transition'?'Transition':cp.kind==='start'?'Race start':'Intermediate checkpoint' }}<span v-if="!cp.is_active"> · not in use</span></div>
                </div>
                <div v-if="can('races.setup') && !race.started_at && cp.kind!=='start'" class="flex flex-wrap gap-2">
                  <button class="btn-secondary !px-3" type="button" @click="beginEditCheckpoint(cp)"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Edit</button>
                  <button class="btn-icon" type="button" :aria-label="cp.is_active?'Disable checkpoint':'Enable checkpoint'" :title="cp.is_active?'Disable checkpoint':'Enable checkpoint'" @click="toggleCheckpoint(cp)"><i :class="cp.is_active?'fa-solid fa-toggle-on':'fa-solid fa-toggle-off'" aria-hidden="true"></i></button>
                  <button class="btn-icon text-error" type="button" aria-label="Delete checkpoint" title="Delete checkpoint" @click="removingCheckpoint=cp"><i class="fa-solid fa-trash" aria-hidden="true"></i></button>
                </div>
              </div>
            </article>
          </div>

          <form v-if="checkpointFormOpen" class="mt-5 rounded-2xl border border-cyan-400/30 bg-canvas p-4" @submit.prevent="saveCheckpoint">
            <div class="flex items-center justify-between gap-3">
              <h4 class="font-bold">{{ editingCheckpoint?'Edit checkpoint':'Add checkpoint' }}</h4>
              <button type="button" class="btn-icon" aria-label="Close checkpoint form" title="Close" @click="closeCheckpointForm"><i class="fa-solid fa-xmark" aria-hidden="true"></i></button>
            </div>
            <div class="mt-4 grid gap-4 sm:grid-cols-2">
              <div class="sm:col-span-2"><label class="label">Name</label><input v-model="checkpoint.name" class="field" placeholder="For example: Run 4 km" required></div>
              <label class="label">Sport<select v-model="checkpoint.discipline" class="field"><option value="">Race-wide</option><option value="swim">Swim</option><option value="bike">Bike</option><option value="run">Run</option></select></label>
              <label class="label">Checkpoint type<select v-model="checkpoint.kind" class="field"><option value="split">Intermediate checkpoint</option><option value="transition">Transition / leg boundary</option><option value="finish">Race finish</option></select></label>
              <label class="label">Distance into this sport (km, optional)<input v-model="checkpoint.distance_km" class="field" type="number" min="0" step="0.001" placeholder="For example: 4"></label>
            </div>
            <details class="mt-4">
              <summary class="cursor-pointer text-sm font-semibold">Optional checkpoint settings</summary>
              <div class="mt-3 grid gap-3 sm:grid-cols-2">
                <label class="label">Order number<input v-model="checkpoint.sequence" class="field" type="number" min="1" max="65535" required><span class="mt-1 block text-xs muted">Only change this when the checkpoint appears in the wrong place.</span></label>
                <label class="label">Internal code<input v-model="checkpoint.code" class="field" placeholder="Generated automatically for new checkpoints"></label>
                <label class="flex gap-2 text-sm"><input v-model="checkpoint.is_required" type="checkbox">Expected for every participant</label>
                <label class="flex gap-2 text-sm"><input v-model="checkpoint.is_active" type="checkbox">Available for timing</label>
              </div>
            </details>
            <p v-if="Object.keys(checkpoint.errors).length" class="mt-3 text-sm text-error">{{ Object.values(checkpoint.errors).join(' · ') }}</p>
            <div class="mt-4 flex gap-2">
              <button class="btn-primary" :disabled="checkpoint.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ editingCheckpoint?'Save checkpoint':'Add checkpoint' }}</button>
              <button type="button" class="btn-secondary" @click="closeCheckpointForm">Cancel</button>
            </div>
          </form>
        </section>
      </div>
    </section>

    <section id="participants" class="mb-6 scroll-mt-32">
      <div class="mb-3 flex items-center justify-between gap-3">
        <div><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">Step 2</p><h2 class="text-xl font-bold">Participants</h2></div>
        <span class="badge">{{ race.entries_count ?? 0 }} total</span>
      </div>

      <div v-if="can('participants.manage') && !race.started_at" class="grid gap-5 xl:grid-cols-[.9fr_1.1fr]">
        <form class="panel-pad" @submit.prevent="addParticipant">
          <h3 class="text-lg font-bold">Add participant</h3>
          <p class="mt-1 text-sm muted">Add one person or relay team here. File import is available under More tools below.</p>
          <div class="mt-4 grid grid-cols-2 gap-3">
            <label class="label">Bib number <span class="font-normal muted">(optional)</span><input v-model="participantForm.bib_number" class="field" maxlength="32" placeholder="101"></label>
            <label class="label">Type<select v-model="participantForm.type" class="field"><option value="solo">Solo athlete</option><option value="relay">3-person relay</option></select></label>
            <label v-if="participantForm.type==='relay'" class="label col-span-2">Team name<input v-model="participantForm.team_name" class="field" required></label>
            <label class="label col-span-2">Category <span class="font-normal muted">(optional)</span><input v-model="participantForm.category" class="field" placeholder="Open, Masters, ..."></label>
          </div>
          <div class="mt-4 space-y-3">
            <div v-for="member in participantForm.members" :key="member.discipline" class="rounded-xl border border-outline p-3">
              <strong>{{ participantForm.type==='solo'?'Athlete':disciplineLabel(member.discipline) }}</strong>
              <div class="mt-3 grid grid-cols-2 gap-2">
                <label class="label">First name<input v-model="member.first_name" class="field" required></label>
                <label class="label">Last name<input v-model="member.last_name" class="field" required></label>
                <label class="label col-span-2">Email <span class="font-normal muted">(optional)</span><input v-model="member.email" class="field" type="email"></label>
              </div>
            </div>
          </div>
          <p v-if="Object.keys(participantForm.errors).length" class="mt-3 text-sm text-error">{{ Object.values(participantForm.errors)[0] }}</p>
          <button class="btn-primary mt-4" :disabled="participantForm.processing"><i class="fa-solid fa-user-plus" aria-hidden="true"></i>Add participant</button>
        </form>

        <section class="panel overflow-hidden">
          <div class="border-b border-outline p-4">
            <h3 class="font-bold">Participants already added</h3>
            <p class="mt-1 text-sm muted">Use Edit only when registration information needs changing.</p>
          </div>
          <div v-if="participants.length" class="divide-y divide-outline">
            <div v-for="participant in participants.slice(0,10)" :key="participant.id" class="flex items-center gap-3 p-4">
              <span class="max-w-24 shrink-0 rounded-xl bg-raised px-3 py-2 font-mono font-bold">{{ bibLabel(participant.bib_number) }}</span>
              <div class="min-w-0 flex-1"><strong class="block truncate">{{ participant.name }}</strong><span class="text-sm muted">{{ participant.type==='relay'?'Relay team':'Solo athlete' }}</span></div>
              <Link :href="`/races/${race.id}/participants/${participant.id}/edit`" class="btn-secondary"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Edit</Link>
            </div>
          </div>
          <div v-else class="p-6 text-center muted">No participants yet.</div>
          <div v-if="participants.length>10" class="border-t border-outline p-4 text-sm muted">Showing 10 of {{ participants.length }}. The full participant list is under More tools.</div>
        </section>
      </div>

      <section v-else class="panel-pad">
        <p class="font-semibold">{{ race.entries_count ?? 0 }} participants are registered.</p>
        <p v-if="race.started_at" class="mt-1 text-sm muted">Participant setup is kept out of the way while the race is running. Use More tools only if a status or registration detail must be corrected.</p>
      </section>
    </section>

    <section id="race-day" class="mb-6 scroll-mt-32">
      <div class="mb-3"><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">Step 3</p><h2 class="text-xl font-bold">Race day</h2></div>
      <section class="panel-pad mb-5">
        <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
          <div>
            <div class="mb-1 text-xs font-bold uppercase tracking-[.18em] text-accent">Shared race clock</div>
            <RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow"/>
            <div class="mt-2"><LiveUpdatesStatus :race-id="race.id"/></div>
          </div>
          <div class="flex flex-wrap gap-2">
            <button v-if="!race.started_at && can('races.control')" class="btn-primary min-w-36" :disabled="!participantsReady || !setupReady" @click="pendingClockAction='start'">
              <i class="fa-solid fa-play" aria-hidden="true"></i>Start race
            </button>
            <button v-else-if="race.started_at && !race.finished_at && can('races.control')" class="btn-danger" @click="pendingClockAction='finish'">
              <i class="fa-solid fa-stop" aria-hidden="true"></i>Finish race
            </button>
            <span v-else-if="race.finished_at" class="badge">Race finished</span>
          </div>
        </div>
        <p v-if="!race.started_at && (!participantsReady || !setupReady)" class="mt-4 text-sm text-warning">
          Before starting: {{ !setupReady?'finish the race setup':'' }}{{ !setupReady && !participantsReady?' and ':'' }}{{ !participantsReady?'add at least one participant':'' }}.
        </p>
        <p v-if="race.started_at && !race.finished_at" class="mt-4 text-sm muted">
          {{ race.settings?.auto_finish===false ? 'Automatic finish is off. Use Finish race when the event is over.' : 'Automatic finish is on. The race will close when every active participant has a finish time; manual finish remains available.' }}
        </p>
      </section>

      <div v-if="can('timings.record')" class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
        <section class="panel overflow-hidden">
          <div class="border-b border-outline p-4">
            <div class="grid gap-3 sm:grid-cols-[minmax(0,1fr)_minmax(220px,.6fr)]">
              <label class="label">Find athlete or team<input v-model="timingSearch" class="field min-h-14 text-lg" placeholder="Bib number or name"></label>
              <label class="label">Checkpoint<select v-model="selectedCheckpointId" class="field min-h-14"><option v-for="cp in activeTimingCheckpoints" :key="cp.id" :value="cp.id">{{ cp.name }}</option></select></label>
            </div>
            <p class="mt-3 text-sm muted">Select where you are standing, then tap the participant once as they pass.</p>
            <p v-if="timingFeedback" class="mt-3 rounded-xl p-3 text-sm font-semibold" :class="timingFeedback.type==='ok'?'bg-emerald-500/10 text-success':'bg-red-500/10 text-error'" role="status">{{ timingFeedback.message }}</p>
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
              <span class="min-w-0"><strong class="block truncate text-lg">{{ participant.name }}</strong><span class="block truncate text-sm muted">{{ participant.type==='relay'?participant.members.map(member=>`${disciplineLabel(member.discipline)}: ${member.name}`).join(' · '):'Solo athlete' }}</span></span>
              <span class="text-sm font-bold" :class="participantRecorded(participant)?'text-success':'text-accent'">{{ participant.status && participant.status!=='registered'?participant.status.toUpperCase():timingSaving.has(participant.id)?'SAVING':participantRecorded(participant)?'RECORDED':'TAP' }}</span>
            </button>
            <p v-if="!filteredTimingParticipants.length" class="p-8 text-center muted">No matching participant.</p>
          </div>
          <div v-else class="p-8 text-center">
            <strong>{{ race.finished_at?'Timing is closed':'Timing opens when the race starts' }}</strong>
            <p class="mt-2 muted">{{ race.finished_at?'Use corrections only for genuine timing fixes.':'Start the shared clock when the race begins.' }}</p>
          </div>
        </section>

        <section class="panel overflow-hidden">
          <div class="border-b border-outline p-4">
            <div class="flex items-center justify-between gap-3"><h3 class="font-bold">Latest times</h3><span class="badge">{{ localCompletedCount }} finished</span></div>
          </div>
          <div v-if="recent.length" class="divide-y divide-outline">
            <div v-for="timing in recent.slice(0,12)" :key="timing.id ?? timing.client_uuid" class="p-4">
              <div class="flex items-center justify-between gap-3">
                <div class="min-w-0"><strong class="block truncate">{{ timing.entry?.display_name ?? bibLabel(timing.entry?.bib_number) }}</strong><span class="text-sm muted">{{ timing.checkpoint?.name }}<span v-if="timing.operator?.name"> · {{ timing.operator.name }}</span></span></div>
                <span class="shrink-0 font-mono font-bold">{{ formatDuration(timing.elapsed_ms,2) }}</span>
              </div>
            </div>
          </div>
          <div v-else class="p-6 text-center muted">No checkpoint times yet.</div>
        </section>
      </div>

      <section v-else class="panel-pad">
        <p class="font-semibold">Your role does not record checkpoint times.</p>
        <p class="mt-1 text-sm muted">You can still see the race status and results if your account has access.</p>
      </section>
    </section>

    <section id="finish" class="mb-6 scroll-mt-32">
      <div class="mb-3"><p class="text-xs font-bold uppercase tracking-[.18em] text-accent">Step 4</p><h2 class="text-xl font-bold">Finish and results</h2></div>
      <section class="panel-pad">
        <div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
          <div>
            <h3 class="font-bold">{{ race.finished_at?'Race complete':'Results are updated as times are recorded' }}</h3>
            <p class="mt-1 muted">{{ localCompletedCount }} of {{ race.entries_count ?? 0 }} participants have a finish time.</p>
          </div>
          <Link :href="`/races/${race.id}/results`" class="btn-primary"><i class="fa-solid fa-trophy" aria-hidden="true"></i>View results</Link>
        </div>

        <div v-if="can('races.setup')" class="mt-5 border-t border-outline pt-5">
          <div class="flex flex-wrap items-center justify-between gap-3">
            <div><h3 class="font-bold">Public results</h3><p class="mt-1 text-sm muted">{{ race.results_published_at?'Published — anyone with the link can view the leaderboard.':'Private — only authorized users can view these results.' }}</p></div>
            <button class="btn-secondary" @click="publishing=true"><i :class="race.results_published_at?'fa-solid fa-eye-slash':'fa-solid fa-globe'" aria-hidden="true"></i>{{ race.results_published_at?'Unpublish':'Publish results' }}</button>
          </div>
          <a v-if="race.results_published_at && race.public_results_token" :href="`/live/${race.public_results_token}`" target="_blank" rel="noopener" class="mt-3 inline-block font-semibold text-accent underline">Open public leaderboard ↗</a>
        </div>
      </section>
    </section>

    <details class="panel-pad">
      <summary class="cursor-pointer text-lg font-bold"><i class="fa-solid fa-toolbox mr-2" aria-hidden="true"></i>More tools</summary>
      <p class="mt-2 text-sm muted">These are useful for imports, poor connectivity or exceptional corrections. They are not part of the normal race flow.</p>
      <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4">
        <Link v-if="can('timings.record')" :href="`/races/${race.id}/station`" class="rounded-xl border border-outline p-4 hover:bg-raised">
          <strong><i class="fa-solid fa-stopwatch mr-2" aria-hidden="true"></i>Focused timing mode</strong><p class="mt-2 text-sm muted">Full-screen checkpoint timing with offline queue support.</p>
        </Link>
        <Link v-if="can('participants.manage')" :href="`/races/${race.id}/participants`" class="rounded-xl border border-outline p-4 hover:bg-raised">
          <strong><i class="fa-solid fa-users mr-2" aria-hidden="true"></i>Full participant list</strong><p class="mt-2 text-sm muted">Search all participants and open detailed edits.</p>
        </Link>
        <Link v-if="can('participants.manage') && !race.started_at" :href="`/races/${race.id}/participants/import`" class="rounded-xl border border-outline p-4 hover:bg-raised">
          <strong><i class="fa-solid fa-file-import mr-2" aria-hidden="true"></i>Import participants</strong><p class="mt-2 text-sm muted">Load many athletes or relay teams from a file.</p>
        </Link>
        <Link v-if="can('races.control')" :href="`/races/${race.id}/control`" class="rounded-xl border border-outline p-4 hover:bg-raised">
          <strong><i class="fa-solid fa-screwdriver-wrench mr-2" aria-hidden="true"></i>Corrections & monitoring</strong><p class="mt-2 text-sm muted">Fix missed times and inspect active timing stations.</p>
        </Link>
      </div>

      <div v-if="isAdmin" class="mt-5 border-t border-red-500/20 pt-5">
        <h3 class="font-bold">Delete race</h3>
        <p class="mt-1 text-sm muted">Moves the race to Deleted races. Participants and timing history are preserved.</p>
        <button class="btn-danger mt-3" @click="deleting=true"><i class="fa-solid fa-trash" aria-hidden="true"></i>Delete race</button>
      </div>
    </details>

    <ConfirmDialog
      v-if="pendingClockAction"
      :title="pendingClockAction==='start'?'Start the race?':'Finish the race?'"
      :message="pendingClockAction==='start'?'Start the shared clock now? All checkpoint times will be measured from this moment.':'Stop the shared race clock now? Use this when automatic finish is off or when the race must be closed manually.'"
      :confirm-label="pendingClockAction==='start'?'Start race':'Finish race'"
      :busy="clockForm.processing"
      @cancel="pendingClockAction=null; clockForm.clearErrors()"
      @confirm="changeClock"
    >
      <p v-if="Object.keys(clockForm.errors).length" class="mt-3 text-error">{{ Object.values(clockForm.errors)[0] }}</p>
    </ConfirmDialog>

    <ConfirmDialog
      v-if="timingConfirmation"
      title="Record this time?"
      :message="timingConfirmation.message"
      confirm-label="Record anyway"
      @cancel="timingConfirmation=null"
      @confirm="confirmTimingOverride"
    />

    <ConfirmDialog
      v-if="removingCheckpoint"
      title="Delete checkpoint?"
      :message="`Delete ${removingCheckpoint.name}? Checkpoints with timing history cannot be deleted.`"
      confirm-label="Delete checkpoint"
      @cancel="removingCheckpoint=null"
      @confirm="confirmCheckpointRemoval"
    />

    <ConfirmDialog
      v-if="publishing"
      :title="race.results_published_at?'Unpublish results?':'Publish participant results?'"
      :message="race.results_published_at?'The current public link will stop working.':'Anyone with the link will be able to view participant names and results.'"
      :confirm-label="race.results_published_at?'Unpublish':'Publish results'"
      :busy="publication.processing"
      @cancel="publishing=false"
      @confirm="publish"
    />

    <ConfirmDialog
      v-if="deleting"
      title="Delete race?"
      :message="`Move ${race.name} to Deleted races? It can be restored later.`"
      confirm-label="Move to Deleted races"
      :busy="deleteForm.processing"
      @cancel="deleting=false"
      @confirm="deleteRace"
    />
  </AppLayout>
</template>
