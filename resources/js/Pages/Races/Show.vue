<script setup lang="ts">
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { ref } from 'vue';
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
  sequence: 15,
  discipline: '',
  kind: 'split',
  distance_km: '',
  is_required: true,
  is_active: true,
});

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
            <p class="mt-1 text-sm muted">Operators choose one active checkpoint for their session/device.</p>
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
                  {{ cp.code }} · {{ cp.discipline ?? 'race' }} · {{ cp.kind }}
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
          <h3 class="mb-3 font-semibold">Add checkpoint</h3>
          <div class="grid gap-3 sm:grid-cols-2">
            <input v-model="checkpoint.name" class="field" placeholder="Bike 10 km" required>
            <input v-model="checkpoint.code" class="field" placeholder="BIKE_10K" required>
            <input v-model="checkpoint.sequence" type="number" class="field" placeholder="Sequence" required>
            <select v-model="checkpoint.discipline" class="field">
              <option value="">Race-wide</option>
              <option value="swim">Swim</option>
              <option value="bike">Bike</option>
              <option value="run">Run</option>
            </select>
            <select v-model="checkpoint.kind" class="field">
              <option value="split">Split</option>
              <option value="transition">Transition</option>
              <option value="finish">Finish</option>
            </select>
            <input v-model="checkpoint.distance_km" type="number" step="0.001" min="0" class="field" placeholder="Distance km">
          </div>
          <div class="mt-3 flex gap-4 text-sm">
            <label><input v-model="checkpoint.is_required" type="checkbox"> Required</label>
            <label><input v-model="checkpoint.is_active" type="checkbox"> Active</label>
          </div>
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
