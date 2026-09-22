<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { ref, computed } from 'vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import type { Checkpoint, Race, PageProps } from '../../types';

interface Organizer {
  id: number;
  name: string;
  email: string;
}

const props = defineProps<{
  race: Race & { checkpoints: Checkpoint[]; organizers: Organizer[]; entries_count?: number };
  organizers: Organizer[];
  serverNow: string;
}>();

const page = usePage<PageProps>();
const deleting = ref(false);
const removingCheckpoint=ref<Checkpoint|null>(null);
const checkpointError=ref('');
const confirmCheckpointRemoval=()=>{if(removingCheckpoint.value)router.delete(`/races/${props.race.id}/checkpoints/${removingCheckpoint.value.id}`,{preserveScroll:true,onSuccess:()=>{removingCheckpoint.value=null;},onError:errors=>{checkpointError.value=Object.values(errors)[0]??'Unable to remove checkpoint';}});};
const deleteForm = useForm({});
const deleteRace = () => deleteForm.delete(`/races/${props.race.id}`, {onSuccess:()=>{deleting.value=false;}});

const raceForm = useForm({
  name: props.race.name,
  event_date: props.race.event_date,
  timezone: props.race.timezone,
  status: props.race.status,
  organizer_ids: props.race.organizers.map((organizer) => organizer.id),
});

const checkpoint = useForm({
  name: '',
  code: '',
  sequence: 25,
  discipline: '',
  kind: 'split',
  distance_km: '',
  is_required: true,
  is_active: true,
});

const checkpointPosition = computed(() => {
  const ordered = [...props.race.checkpoints].sort((a,b)=>a.sequence-b.sequence);
  const before = ordered.filter(cp=>cp.sequence<Number(checkpoint.sequence)).at(-1);
  const after = ordered.find(cp=>cp.sequence>Number(checkpoint.sequence));
  if(ordered.some(cp=>cp.sequence===Number(checkpoint.sequence)))return 'This order number is already used. Choose an unused number.';
  return `${before ? 'After '+before.name : 'Before all checkpoints'}${after ? ' · before '+after.name : ' · last checkpoint'}`;
});
const kindLabel = (kind:string) => ({start:'Shared race start',split:'Intermediate timing point',transition:'Leg boundary / transition',finish:'Overall race finish'}[kind] ?? kind);
const saveRace = () => raceForm.put(`/races/${props.race.id}`);
const addCheckpoint = () => checkpoint.post(`/races/${props.race.id}/checkpoints`, {
  preserveScroll: true,
  onSuccess: () => checkpoint.reset(),
});

const toggleCheckpoint = (cp: Checkpoint) => {
  router.put(`/races/${props.race.id}/checkpoints/${cp.id}`, {
    name: cp.name,
    code: cp.code,
    sequence: cp.sequence,
    discipline: cp.discipline,
    kind: cp.kind,
    distance_km: cp.distance_km,
    is_required: cp.is_required,
    is_active: !cp.is_active,
  }, { preserveScroll: true, onError:errors=>{checkpointError.value=Object.values(errors)[0]??'Unable to update checkpoint';} });
};

const removeCheckpoint = (cp: Checkpoint) => {
  if (cp.kind === 'start') return;
  removingCheckpoint.value=cp;checkpointError.value='';
};
</script>

<template>
  <Head :title="race.name" />
  <AppLayout :title="race.name">
    <div class="grid gap-6 lg:grid-cols-2">
      <form class="panel-pad" @submit.prevent="saveRace">
        <h2 class="mb-4 text-lg font-bold">Race settings</h2>
        <div class="space-y-4">
          <div>
            <label for="race-name" class="label">Name</label>
            <input id="race-name" v-model="raceForm.name" class="field" required>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label for="race-date" class="label">Date</label>
              <input id="race-date" v-model="raceForm.event_date" type="date" class="field" required>
            </div>
            <div>
              <label for="race-timezone" class="label">Timezone</label>
              <input id="race-timezone" v-model="raceForm.timezone" class="field" required>
            </div>
          </div>
          <div>
            <label for="race-status" class="label">Status</label>
            <select id="race-status" v-model="raceForm.status" class="field">
              <template v-if="!race.started_at">
                <option value="draft">Draft</option>
                <option value="ready">Ready</option>
              </template>
              <option v-else-if="!race.finished_at" value="running">Running</option>
              <template v-else>
                <option value="finished">Finished</option>
                <option value="archived">Archived</option>
              </template>
            </select>
            <p class="mt-1 text-xs muted">Running/finished status is controlled by the shared race clock.</p>
          </div>
          <div v-if="organizers.length">
            <label class="label">Organizers</label>
            <div class="grid gap-2 rounded-xl border border-slate-800 p-3">
              <label v-for="organizer in organizers" :key="organizer.id" class="flex gap-2">
                <input v-model="raceForm.organizer_ids" type="checkbox" :value="organizer.id">
                <span>{{ organizer.name }} <span class="muted">{{ organizer.email }}</span></span>
              </label>
            </div>
          </div>
        </div>
        <p v-if="Object.keys(raceForm.errors).length" class="mt-3 text-red-300">{{ Object.values(raceForm.errors)[0] }}</p>
        <button class="btn-primary mt-5" :disabled="raceForm.processing">Save settings</button>
      </form>

      <div class="panel-pad">
        <div class="mb-4 flex items-start justify-between gap-3">
          <div>
            <h2 class="text-lg font-bold">Checkpoints</h2>
            <p class="mt-1 text-sm muted">Listed in race order. Record each transition at its exit, when the next leg starts. Race Start is controlled by the shared clock, not a station.</p>
          </div>
          <span class="badge">{{ race.checkpoints.length }} total</span>
        </div>

        <p v-if="checkpointError" role="alert" class="mb-3 text-red-300">{{ checkpointError }}</p><div class="space-y-2">
          <div
            v-for="cp in race.checkpoints"
            :key="cp.id"
            class="rounded-xl border border-slate-800 bg-slate-950/60 p-3"
          >
            <div class="flex items-start justify-between gap-3">
              <div>
                <div class="font-semibold">{{ cp.sequence }} · {{ cp.name }}</div>
                <div class="text-sm muted">
                  {{ cp.discipline ?? 'Race-wide' }} · {{ kindLabel(cp.kind) }}
                  <span v-if="cp.distance_km">· {{ cp.distance_km }} km</span>
                </div>
              </div>
              <span class="badge">{{ cp.is_active ? 'active' : 'off' }}</span>
            </div>
            <div class="mt-3 flex flex-wrap gap-2">
              <button type="button" class="btn-secondary !px-3 !py-1.5 text-xs" @click="toggleCheckpoint(cp)">
                {{ cp.is_active ? 'Disable' : 'Enable' }}
              </button>
              <button
                v-if="cp.kind !== 'start'"
                type="button"
                class="btn-danger !px-3 !py-1.5 text-xs"
                @click="removeCheckpoint(cp)"
              >
                Delete
              </button>
            </div>
          </div>
        </div>

        <form class="mt-5 border-t border-slate-800 pt-5" @submit.prevent="addCheckpoint">
          <h3 class="mb-2 font-semibold">Add checkpoint</h3>
          <p class="mb-4 text-sm muted">Add a place where an organizer records each participant passing. For example, “Run 4 km” is an intermediate timing point between Run Start and Run Finish.</p>
          <div class="grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2"><label for="checkpoint-name" class="label">Checkpoint name</label><input id="checkpoint-name" v-model="checkpoint.name" class="field" placeholder="For example: Run 4 km" maxlength="255" required><p class="mt-1 text-xs muted">Shown to organizers at the timing station and in results.</p></div>
            <div><label for="checkpoint-sequence" class="label">Order in the race</label><input id="checkpoint-sequence" v-model="checkpoint.sequence" type="number" min="1" max="65535" step="1" class="field" required aria-describedby="checkpoint-order-help"><p id="checkpoint-order-help" class="mt-1 text-xs muted">Smaller numbers come first. Use 25 between Run Start (20) and Run Finish (30) in a new race. Each number must be unique.</p><p class="mt-2 text-sm text-cyan-200" aria-live="polite">{{ checkpointPosition }}</p></div>
            <div><label for="checkpoint-discipline" class="label">Sport / leg</label><select id="checkpoint-discipline" v-model="checkpoint.discipline" class="field"><option value="">Race-wide (no specific sport)</option><option value="swim">Swim</option><option value="run">Run</option><option value="bike">Bike</option></select><p class="mt-1 text-xs muted">For a transition exit, choose the sport starting there. Relay timings are linked to that sport’s athlete.</p></div>
            <div><label for="checkpoint-kind" class="label">What happens here?</label><select id="checkpoint-kind" v-model="checkpoint.kind" class="field"><option value="split">Intermediate timing point</option><option value="transition">Leg boundary / transition</option><option value="finish">Overall race finish</option></select><p class="mt-1 text-xs muted">Intermediate: partway through a leg. Boundary: a leg ends or the next begins. Overall finish: completes the participant’s result; use only at the end of the whole race.</p></div>
            <div><label for="checkpoint-distance" class="label">Distance into this leg (km, optional)</label><input id="checkpoint-distance" v-model="checkpoint.distance_km" type="number" step="0.001" min="0" class="field" placeholder="For example: 4"><p class="mt-1 text-xs muted">Use 0 at a leg’s start, or leave blank if unknown. This is a distance label, not a target time or total race distance.</p></div>
          </div>
          <div class="mt-4 space-y-3 text-sm">
            <div><label class="flex gap-2"><input v-model="checkpoint.is_required" type="checkbox"> Expected for every participant</label><p class="mt-1 text-xs muted">Warn organizers at later checkpoints if this timing is missing. They can explicitly record anyway.</p></div>
            <div><label class="flex gap-2"><input v-model="checkpoint.is_active" type="checkbox"> Available at timing stations</label><p class="mt-1 text-xs muted">Turn off to keep this checkpoint in the setup without allowing new taps there.</p></div>
          </div>
          <details class="mt-4"><summary class="cursor-pointer text-sm muted">Advanced: checkpoint code</summary><label for="checkpoint-code" class="label mt-3">Unique code (optional)</label><input id="checkpoint-code" v-model="checkpoint.code" class="field" placeholder="Generated automatically from the name" maxlength="48"><p class="mt-1 text-xs muted">A stable identifier for integrations. Normally leave this blank. If supplied, it must be unique within this race.</p></details>
          <p v-if="Object.keys(checkpoint.errors).length" role="alert" class="mt-3 text-red-300">{{ Object.values(checkpoint.errors).join(' · ') }}</p><button class="btn-secondary mt-4" :disabled="checkpoint.processing">Add checkpoint</button>
        </form>
      </div>
    </div>
    <section v-if="page.props.auth.user?.role==='admin'" class="panel-pad mt-6 border-red-500/30">
      <h2 class="font-bold">Delete race</h2>
      <p class="mt-2 muted">Remove this race from active lists. Participants, timings and organizer assignments are preserved. You can restore it from Deleted races.</p>
      <button class="btn-danger mt-4" @click="deleting=true">Delete race</button>
    </section>
    <ConfirmDialog v-if="deleting" title="Delete race?" :message="`Move ${race.name} to Deleted races? It will no longer be available at timing stations until restored.`"
      confirm-label="Move to Deleted races" :busy="deleteForm.processing" @cancel="deleting=false" @confirm="deleteRace" />
<ConfirmDialog v-if="removingCheckpoint" title="Delete checkpoint?" :message="`Delete ${removingCheckpoint.name}? Checkpoints with timing history cannot be deleted.`" confirm-label="Delete checkpoint" @cancel="removingCheckpoint=null" @confirm="confirmCheckpointRemoval"><p v-if="checkpointError" class="mt-2 text-red-300">{{ checkpointError }}</p></ConfirmDialog>
  </AppLayout>
</template>
