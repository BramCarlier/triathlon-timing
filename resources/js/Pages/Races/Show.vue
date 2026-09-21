<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Checkpoint, Race } from '../../types';

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
  }, { preserveScroll: true });
};

const removeCheckpoint = (cp: Checkpoint) => {
  if (cp.kind === 'start') return;
  if (!confirm(`Delete checkpoint “${cp.name}”? Checkpoints with timings cannot be deleted.`)) return;
  router.delete(`/races/${props.race.id}/checkpoints/${cp.id}`, { preserveScroll: true });
};
</script>

<template>
  <Head :title="race.name" />
  <AppLayout :title="race.name">
    <div class="mb-5 grid gap-2 sm:grid-cols-4">
      <Link :href="`/races/${race.id}/participants`" class="btn-secondary">
        Participants ({{ race.entries_count ?? 0 }})
      </Link>
      <Link :href="`/races/${race.id}/station`" class="btn-primary">Timing station</Link>
      <Link :href="`/races/${race.id}/control`" class="btn-secondary">Race control</Link>
      <Link :href="`/races/${race.id}/results`" class="btn-secondary">Results</Link>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
      <form class="panel-pad" @submit.prevent="saveRace">
        <h2 class="mb-4 text-lg font-bold">Race settings</h2>
        <div class="space-y-4">
          <div>
            <label class="label">Name</label>
            <input v-model="raceForm.name" class="field" required>
          </div>
          <div class="grid gap-4 sm:grid-cols-2">
            <div>
              <label class="label">Date</label>
              <input v-model="raceForm.event_date" type="date" class="field" required>
            </div>
            <div>
              <label class="label">Timezone</label>
              <input v-model="raceForm.timezone" class="field" required>
            </div>
          </div>
          <div>
            <label class="label">Status</label>
            <select v-model="raceForm.status" class="field">
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

        <div class="space-y-2">
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
          <button class="btn-secondary mt-4" :disabled="checkpoint.processing">Add checkpoint</button>
        </form>
      </div>
    </div>
  </AppLayout>
</template>
