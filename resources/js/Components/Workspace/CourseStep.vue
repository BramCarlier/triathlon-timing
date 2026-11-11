<script setup lang="ts">
import { computed, ref } from 'vue';
import { router, useForm } from '@inertiajs/vue3';
import Icon from '../Icon.vue';
import ConfirmDialog from '../ConfirmDialog.vue';
import { checkpointDistanceText } from '../../checkpointDistance';
import { tr } from '../../i18n';
import type { Checkpoint, Race } from '../../types';
import type { CheckpointAssignment, Official } from './types';

/** Step 1: race details, distances, checkpoints and optional Official accounts. */
const props = defineProps<{
  race: Race & { checkpoints: Checkpoint[] };
  officials: Official[];
  checkpointAssignments: CheckpointAssignment[];
  mailConfigured: boolean;
}>();

const showOfficialTools = ref(false);
const orderedCheckpoints = computed(() => [...props.race.checkpoints].sort((a, b) => a.sequence - b.sequence));
const setupCheckpoints = computed(() => orderedCheckpoints.value.filter(cp => cp.kind !== 'start'));

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
const saveRace = () =>
  raceForm.put(`/races/${props.race.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      editingRaceDetails.value = false;
    },
  });

const checkpointFormOpen = ref(false);
const editingCheckpoint = ref<Checkpoint | null>(null);
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
  assigningCheckpoint.value = null;
  officialForm.clearErrors();
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
  const options = { preserveScroll: true, onSuccess: closeCheckpointForm };
  if (editingCheckpoint.value) checkpoint.put(`/races/${props.race.id}/checkpoints/${editingCheckpoint.value.id}`, options);
  else checkpoint.post(`/races/${props.race.id}/checkpoints`, options);
};
const removingCheckpoint = ref<Checkpoint | null>(null);
const checkpointError = ref('');
const confirmCheckpointRemoval = () => {
  if (!removingCheckpoint.value) return;
  router.delete(`/races/${props.race.id}/checkpoints/${removingCheckpoint.value.id}`, {
    preserveScroll: true,
    onSuccess: () => {
      removingCheckpoint.value = null;
      checkpointError.value = '';
    },
    onError: errors => {
      checkpointError.value = String(Object.values(errors)[0] ?? tr('Unable to delete checkpoint'));
    },
  });
};

const assigningCheckpoint = ref<Checkpoint | null>(null);
const officialForm = useForm({
  checkpoint_id: null as number | null,
  user_id: null as number | null,
  name: '',
  email: '',
  delivery: props.mailConfigured ? 'email' : 'manual',
  password: '',
});
const assignmentsFor = (checkpointId: number) => props.checkpointAssignments.filter(item => item.checkpoint_id === checkpointId);
const assignmentForOfficial = (officialId: number) => props.checkpointAssignments.find(item => item.user_id === officialId);
const officialFilterTokens = computed(() => `${officialForm.name} ${officialForm.email}`.trim().toLowerCase().split(/\s+/).filter(Boolean));
const availableOfficials = computed(() =>
  props.officials.filter(official => {
    const haystack = `${official.name} ${official.email}`.toLowerCase();
    return officialFilterTokens.value.every(token => haystack.includes(token));
  }),
);
const selectedOfficial = computed(() => props.officials.find(official => official.id === officialForm.user_id) ?? null);
const updateOfficialField = (field: 'name' | 'email', event: Event) => {
  officialForm.user_id = null;
  const value = (event.target as HTMLInputElement).value;
  if (field === 'name') officialForm.name = value;
  else officialForm.email = value;
};
const selectOfficial = (official: Official) => {
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
const beginAssignOfficial = (cp: Checkpoint) => {
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
const assignOfficial = () =>
  officialForm.post(`/races/${props.race.id}/official-assignments`, {
    preserveScroll: true,
    onSuccess: closeOfficialForm,
  });
const removeOfficial = (official: Official) =>
  router.delete(`/races/${props.race.id}/official-assignments/${official.id}`, { preserveScroll: true });
</script>

<template>
  <div>
    <div class="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
      <section class="rounded-lg border border-outline p-4">
        <div v-if="!editingRaceDetails">
          <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
              <h3 class="font-bold">{{ $t('Race details') }}</h3>
              <p class="mt-2 text-sm muted">{{ raceForm.event_date }} · {{ raceForm.timezone }}</p>
            </div>
            <button type="button" class="btn-secondary !px-3" @click="editingRaceDetails = true">
              <Icon name="pen-to-square" />
              {{ $t('Edit details') }}
            </button>
          </div>
          <div class="mt-4 grid grid-cols-3 gap-2 text-center">
            <div class="rounded-md bg-canvas p-3">
              <strong class="block">{{ raceForm.swim_km }} {{ $t('km') }}</strong>
              <span class="text-xs muted">{{ $t('Swim') }}</span>
            </div>
            <div class="rounded-md bg-canvas p-3">
              <strong class="block">{{ raceForm.bike_km }} {{ $t('km') }}</strong>
              <span class="text-xs muted">{{ $t('Bike') }}</span>
            </div>
            <div class="rounded-md bg-canvas p-3">
              <strong class="block">{{ raceForm.run_km }} {{ $t('km') }}</strong>
              <span class="text-xs muted">{{ $t('Run') }}</span>
            </div>
          </div>
        </div>
        <form v-else @submit.prevent="saveRace">
          <div class="flex items-center justify-between gap-3">
            <h3 class="font-bold">{{ $t('Edit race details') }}</h3>
            <button
              type="button"
              class="btn-icon"
              :aria-label="$t('Close race details')"
              @click="
                editingRaceDetails = false;
                raceForm.clearErrors();
              "
            >
              <Icon name="xmark" />
            </button>
          </div>
          <div class="mt-4 space-y-4">
            <label class="label">
              {{ $t('Race name') }}
              <input v-model="raceForm.name" class="field" required />
            </label>
            <label class="label">
              {{ $t('Date') }}
              <input v-model="raceForm.event_date" type="date" class="field" required />
            </label>
            <fieldset>
              <legend class="label">{{ $t('Distances') }}</legend>
              <div class="grid gap-3 sm:grid-cols-3">
                <label class="label">
                  {{ $t('Swim (km)') }}
                  <input v-model="raceForm.swim_km" class="field" type="number" min="0.001" step="0.001" required />
                </label>
                <label class="label">
                  {{ $t('Bike (km)') }}
                  <input v-model="raceForm.bike_km" class="field" type="number" min="0.001" step="0.001" required />
                </label>
                <label class="label">
                  {{ $t('Run (km)') }}
                  <input v-model="raceForm.run_km" class="field" type="number" min="0.001" step="0.001" required />
                </label>
              </div>
            </fieldset>
            <label class="label">
              {{ $t('Timezone') }}
              <input v-model="raceForm.timezone" class="field" required />
            </label>
          </div>
          <p v-if="Object.keys(raceForm.errors).length" class="mt-3 text-sm text-error">{{ Object.values(raceForm.errors)[0] }}</p>
          <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn-primary" :disabled="raceForm.processing">
              <Icon name="floppy-disk" />
              {{ $t('Save details') }}
            </button>
            <button type="button" class="btn-secondary" @click="editingRaceDetails = false">{{ $t('Cancel') }}</button>
          </div>
        </form>
      </section>

      <section class="rounded-lg border border-outline p-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
          <div>
            <h3 class="font-bold">{{ $t('Checkpoints') }}</h3>
            <p class="mt-1 text-sm muted">
              {{ $t('Anyone with the race-day link can choose any active checkpoint. Official accounts are optional.') }}
            </p>
          </div>
          <button v-if="!checkpointFormOpen" class="btn-primary" type="button" @click="beginAddCheckpoint">
            <Icon name="plus" />
            {{ $t('Add checkpoint') }}
          </button>
        </div>

        <p v-if="checkpointError" class="mt-3 text-sm text-error" role="alert">{{ checkpointError }}</p>
        <div class="mt-4 rounded-md border border-outline bg-canvas p-3">
          <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
            <div>
              <strong>{{ $t('Official accounts are optional') }}</strong>
              <p class="mt-1 text-sm muted">
                {{
                  $t(
                    'Use them only when you want named accounts locked to specific checkpoints. The race-day link works without accounts or assignments.',
                  )
                }}
              </p>
            </div>
            <button type="button" class="btn-secondary shrink-0" @click="showOfficialTools = !showOfficialTools">
              <Icon name="user-shield" />
              {{ showOfficialTools ? $t('Hide official setup') : $t('Set up officials') }}
            </button>
          </div>
        </div>
        <div class="mt-4 space-y-3">
          <article v-for="cp in setupCheckpoints" :key="cp.id" class="rounded-md border border-outline p-3">
            <div class="flex flex-wrap items-start justify-between gap-3">
              <div class="min-w-0">
                <strong class="block">{{ $t(cp.name) }}</strong>
                <span class="mt-1 block text-sm muted">{{ checkpointDistanceText(race, cp) }}</span>
              </div>
              <div class="flex flex-wrap gap-2">
                <button
                  class="btn-secondary !px-3"
                  type="button"
                  :aria-expanded="checkpointFormOpen && editingCheckpoint?.id === cp.id"
                  @click="beginEditCheckpoint(cp)"
                >
                  <Icon name="pen-to-square" />
                  {{ $t('Edit') }}
                </button>
                <button
                  v-if="showOfficialTools"
                  class="btn-secondary !px-3"
                  type="button"
                  :aria-expanded="assigningCheckpoint?.id === cp.id"
                  @click="beginAssignOfficial(cp)"
                >
                  <Icon name="user-plus" />
                  {{ $t('Assign account') }}
                </button>
                <button
                  class="btn-icon text-error"
                  type="button"
                  :aria-label="$t('Delete checkpoint')"
                  :title="$t('Delete checkpoint')"
                  @click="removingCheckpoint = cp"
                >
                  <Icon name="trash" />
                </button>
              </div>
            </div>

            <div v-if="showOfficialTools" class="mt-3 flex flex-wrap gap-2">
              <span v-if="!assignmentsFor(cp.id).length" class="text-sm muted">
                {{ $t('No account assigned — that is fine for the race-day link') }}
              </span>
              <span
                v-for="assignment in assignmentsFor(cp.id)"
                :key="assignment.id"
                class="inline-flex items-center gap-2 rounded-full bg-canvas px-3 py-2 text-sm"
              >
                <Icon name="user" />
                {{ assignment.user.name }}
                <button
                  type="button"
                  class="text-error"
                  :aria-label="$t('Remove :name', { name: assignment.user.name })"
                  @click="removeOfficial(assignment.user)"
                >
                  <Icon name="xmark" />
                </button>
              </span>
            </div>

            <form
              v-if="checkpointFormOpen && editingCheckpoint?.id === cp.id"
              class="mt-4 rounded-lg border border-buoy/40 bg-canvas p-4"
              @submit.prevent="saveCheckpoint"
            >
              <div class="flex items-center justify-between gap-3">
                <h4 class="font-bold">{{ $t('Edit checkpoint') }}</h4>
                <button type="button" class="btn-icon" :aria-label="$t('Close checkpoint form')" @click="closeCheckpointForm">
                  <Icon name="xmark" />
                </button>
              </div>
              <div class="mt-4 grid gap-4 sm:grid-cols-2">
                <div class="sm:col-span-2">
                  <label class="label" :for="`checkpoint-name-${cp.id}`">{{ $t('Name') }}</label>
                  <input
                    :id="`checkpoint-name-${cp.id}`"
                    v-model="checkpoint.name"
                    class="field"
                    :placeholder="$t('For example: Run 4 km')"
                    required
                  />
                </div>
                <div>
                  <label class="label" :for="`checkpoint-sport-${cp.id}`">{{ $t('Sport') }}</label>
                  <select :id="`checkpoint-sport-${cp.id}`" v-model="checkpoint.discipline" class="field">
                    <option value="">{{ $t('Race-wide') }}</option>
                    <option value="swim">{{ $t('Swim') }}</option>
                    <option value="bike">{{ $t('Bike') }}</option>
                    <option value="run">{{ $t('Run') }}</option>
                  </select>
                </div>
                <div>
                  <label class="label" :for="`checkpoint-kind-${cp.id}`">{{ $t('What happens here?') }}</label>
                  <select :id="`checkpoint-kind-${cp.id}`" v-model="checkpoint.kind" class="field">
                    <option value="split">{{ $t('Timing point') }}</option>
                    <option value="transition">{{ $t('Transition') }}</option>
                    <option value="finish">{{ $t('Finish') }}</option>
                  </select>
                </div>
                <div class="sm:col-span-2">
                  <label class="label" :for="`checkpoint-distance-${cp.id}`">
                    {{ $t('Distance into this sport (km)') }}
                    <span class="font-normal muted">{{ $t('(optional)') }}</span>
                  </label>
                  <input
                    :id="`checkpoint-distance-${cp.id}`"
                    v-model="checkpoint.distance_km"
                    class="field"
                    type="number"
                    min="0"
                    step="0.001"
                  />
                </div>
              </div>
              <p v-if="Object.keys(checkpoint.errors).length" class="mt-3 text-sm text-error">
                {{ Object.values(checkpoint.errors).join(' · ') }}
              </p>
              <div class="mt-4 flex flex-wrap gap-2">
                <button class="btn-primary" :disabled="checkpoint.processing">
                  <Icon name="floppy-disk" />
                  {{ $t('Save checkpoint') }}
                </button>
                <button type="button" class="btn-secondary" @click="closeCheckpointForm">{{ $t('Cancel') }}</button>
              </div>
            </form>

            <form
              v-if="showOfficialTools && assigningCheckpoint?.id === cp.id"
              class="mt-4 rounded-lg border border-buoy/40 bg-canvas p-4"
              @submit.prevent="assignOfficial"
            >
              <div class="flex items-center justify-between gap-3">
                <div>
                  <h4 class="font-bold">{{ $t('Optional Official account') }}</h4>
                  <p class="mt-1 text-sm muted">{{ $t(cp.name) }} {{ $t('· only needed if you want account-based access') }}</p>
                </div>
                <button type="button" class="btn-icon" :aria-label="$t('Close official form')" @click="closeOfficialForm">
                  <Icon name="xmark" />
                </button>
              </div>
              <div class="mt-4 grid gap-3 sm:grid-cols-2">
                <label class="label">
                  {{ $t('Name') }}
                  <input
                    :value="officialForm.name"
                    class="field"
                    autocomplete="off"
                    :placeholder="$t('Official name')"
                    required
                    @input="updateOfficialField('name', $event)"
                  />
                </label>
                <label class="label">
                  {{ $t('Email') }}
                  <input
                    :value="officialForm.email"
                    type="email"
                    class="field"
                    autocomplete="off"
                    :placeholder="$t('official@example.com')"
                    required
                    @input="updateOfficialField('email', $event)"
                  />
                </label>
              </div>
              <p class="mt-2 text-xs muted">
                {{
                  $t(
                    'Available Officials are shown below. Typing a name or email filters the list. If you do not select an existing account, a new Official is created from these details.',
                  )
                }}
              </p>

              <div v-if="selectedOfficial" class="mt-3 rounded-md border border-success/40 bg-success/10 p-3">
                <div class="flex items-start justify-between gap-3">
                  <div>
                    <span class="text-xs font-bold text-success">{{ $t('Existing Official selected') }}</span>
                    <strong class="mt-1 block">{{ selectedOfficial.name }}</strong>
                    <span class="mt-1 block text-sm muted">
                      {{ selectedOfficial.email }}{{ selectedOfficial.role === 'admin' ? ' · ' + $t('Organizer (admin)') : '' }}
                    </span>
                    <span v-if="assignmentForOfficial(selectedOfficial.id)" class="mt-1 block text-xs muted">
                      {{ $t('Currently assigned to') }} {{ $t(assignmentForOfficial(selectedOfficial.id)?.checkpoint?.name ?? '') }}
                    </span>
                  </div>
                  <button type="button" class="btn-secondary !px-3" @click="clearOfficialSelection">
                    <Icon name="rotate" />
                    {{ $t('Change') }}
                  </button>
                </div>
              </div>

              <div v-else-if="availableOfficials.length" class="mt-3 rounded-md border border-buoy/40 bg-canvas p-2">
                <div class="flex items-center justify-between gap-3 px-2 pb-2">
                  <p class="text-xs font-bold text-accent">
                    {{ officialFilterTokens.length ? $t('Matching officials') : $t('Available officials') }}
                  </p>
                  <span class="text-xs muted">{{ availableOfficials.length }} {{ $t('shown') }}</span>
                </div>
                <div class="max-h-60 space-y-1 overflow-y-auto pr-1">
                  <button
                    v-for="official in availableOfficials"
                    :key="official.id"
                    type="button"
                    class="w-full rounded-md border border-outline p-3 text-left hover:border-buoy hover:bg-raised"
                    @click="selectOfficial(official)"
                  >
                    <span class="flex flex-wrap items-center justify-between gap-2">
                      <strong>{{ official.name }}</strong>
                      <span v-if="official.role === 'admin'" class="badge">{{ $t('Organizer (admin)') }}</span>
                    </span>
                    <span class="mt-1 block text-sm muted">{{ official.email }}</span>
                    <span v-if="assignmentForOfficial(official.id)" class="mt-1 block text-xs muted">
                      {{ $t('Assigned to') }} {{ $t(assignmentForOfficial(official.id)?.checkpoint?.name ?? '') }}
                    </span>
                  </button>
                </div>
              </div>

              <p v-else class="mt-3 rounded-md bg-canvas p-3 text-sm muted">
                {{ $t('No existing Official matches these details. Complete the form below to create and assign a new Official.') }}
              </p>

              <div v-if="!officialForm.user_id && !availableOfficials.length" class="mt-4 space-y-3">
                <label class="label">
                  {{ $t('Account setup') }}
                  <select v-model="officialForm.delivery" class="field">
                    <option value="email" :disabled="!mailConfigured">{{ $t('Send password setup email') }}</option>
                    <option value="manual">{{ $t('Use a temporary password') }}</option>
                  </select>
                </label>
                <label v-if="officialForm.delivery === 'manual'" class="label">
                  {{ $t('Temporary password') }}
                  <input v-model="officialForm.password" type="password" minlength="12" class="field" required />
                </label>
              </div>
              <p class="mt-2 text-xs muted">
                {{ $t('Organizers (admin) are included in the Official list and can be assigned to a checkpoint.') }}
              </p>
              <p v-if="Object.keys(officialForm.errors).length" class="mt-3 text-sm text-error">
                {{ Object.values(officialForm.errors)[0] }}
              </p>
              <button
                v-if="officialForm.user_id || !availableOfficials.length"
                class="btn-primary mt-4"
                :disabled="officialForm.processing"
              >
                <Icon name="user-check" />
                {{ officialForm.user_id ? $t('Assign to checkpoint') : $t('Create & assign Official') }}
              </button>
              <p v-else class="mt-4 rounded-md bg-canvas p-3 text-sm muted">
                {{
                  $t(
                    'Select an Official from the list, or keep typing the name and email until there is no existing match to create a new account.',
                  )
                }}
              </p>
            </form>
          </article>
        </div>

        <form
          v-if="checkpointFormOpen && !editingCheckpoint"
          class="mt-4 rounded-lg border border-buoy/40 bg-canvas p-4"
          @submit.prevent="saveCheckpoint"
        >
          <div class="flex items-center justify-between gap-3">
            <h4 class="font-bold">{{ $t('Add checkpoint') }}</h4>
            <button type="button" class="btn-icon" :aria-label="$t('Close checkpoint form')" @click="closeCheckpointForm">
              <Icon name="xmark" />
            </button>
          </div>
          <div class="mt-4 grid gap-4 sm:grid-cols-2">
            <div class="sm:col-span-2">
              <label class="label" for="checkpoint-add-name">{{ $t('Name') }}</label>
              <input id="checkpoint-add-name" v-model="checkpoint.name" class="field" :placeholder="$t('For example: Run 4 km')" required />
            </div>
            <div>
              <label class="label" for="checkpoint-add-sport">{{ $t('Sport') }}</label>
              <select id="checkpoint-add-sport" v-model="checkpoint.discipline" class="field">
                <option value="">{{ $t('Race-wide') }}</option>
                <option value="swim">{{ $t('Swim') }}</option>
                <option value="bike">{{ $t('Bike') }}</option>
                <option value="run">{{ $t('Run') }}</option>
              </select>
            </div>
            <div>
              <label class="label" for="checkpoint-add-kind">{{ $t('What happens here?') }}</label>
              <select id="checkpoint-add-kind" v-model="checkpoint.kind" class="field">
                <option value="split">{{ $t('Timing point') }}</option>
                <option value="transition">{{ $t('Transition') }}</option>
                <option value="finish">{{ $t('Finish') }}</option>
              </select>
            </div>
            <div class="sm:col-span-2">
              <label class="label" for="checkpoint-add-distance">
                {{ $t('Distance into this sport (km)') }}
                <span class="font-normal muted">{{ $t('(optional)') }}</span>
              </label>
              <input id="checkpoint-add-distance" v-model="checkpoint.distance_km" class="field" type="number" min="0" step="0.001" />
            </div>
          </div>
          <p v-if="Object.keys(checkpoint.errors).length" class="mt-3 text-sm text-error">
            {{ Object.values(checkpoint.errors).join(' · ') }}
          </p>
          <div class="mt-4 flex flex-wrap gap-2">
            <button class="btn-primary" :disabled="checkpoint.processing">
              <Icon name="plus" />
              {{ $t('Add checkpoint') }}
            </button>
            <button type="button" class="btn-secondary" @click="closeCheckpointForm">{{ $t('Cancel') }}</button>
          </div>
        </form>
      </section>
    </div>
    <ConfirmDialog
      v-if="removingCheckpoint"
      :title="$t('Delete checkpoint?')"
      :message="$t('Delete :name?', { name: $t(removingCheckpoint.name) })"
      :confirm-label="$t('Delete checkpoint')"
      @cancel="removingCheckpoint = null"
      @confirm="confirmCheckpointRemoval"
    />
  </div>
</template>
