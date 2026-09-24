<script setup lang="ts">
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { jsonRequest } from '../lib';

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

export interface AthleteMemberValue {
  discipline?: string;
  athlete_id: number | null;
  first_name: string;
  last_name: string;
  email: string;
  club: string;
}

const props = defineProps<{
  raceId: number;
  modelValue: AthleteMemberValue;
  title: string;
  bibNumber?: string|null;
  initialOptions: AthleteChoice[];
}>();

const emit = defineEmits<{
  'update:modelValue': [value: AthleteMemberValue];
}>();

const results = ref<AthleteChoice[]>([...props.initialOptions]);
const selected = ref<AthleteChoice|null>(null);
const loading = ref(false);
const error = ref('');
let searchTimer:number|undefined;
let requestNumber = 0;

const textLookup = computed(() => [
  props.modelValue.first_name,
  props.modelValue.last_name,
  props.modelValue.email,
].map(value => value?.trim()).filter(Boolean).join(' ').trim());

const bibLookup = computed(() => (props.bibNumber ?? '').trim());
const hasLookup = computed(() => textLookup.value.length >= 2 || bibLookup.value.length > 0);
const listTitle = computed(() => hasLookup.value ? 'Matching athletes' : 'Recent athletes');

const selectAthlete = (athlete:AthleteChoice) => {
  if (athlete.already_in_race) return;
  selected.value = athlete;
  results.value = [];
  emit('update:modelValue', {
    ...props.modelValue,
    athlete_id: athlete.id,
    first_name: athlete.first_name,
    last_name: athlete.last_name,
    email: athlete.email ?? '',
    club: athlete.club ?? '',
  });
};

const clearSelection = () => {
  selected.value = null;
  results.value = [];
  emit('update:modelValue', {
    ...props.modelValue,
    athlete_id:null,
    first_name:'',
    last_name:'',
    email:'',
    club:'',
  });
};

const updateField = (field:'first_name'|'last_name'|'email'|'club', event:Event) => {
  const target = event.target as HTMLInputElement;
  emit('update:modelValue', { ...props.modelValue, athlete_id:null, [field]:target.value });
};

watch(() => props.modelValue.athlete_id, athleteId => {
  if (!athleteId) selected.value = null;
});

watch([textLookup, bibLookup], ([text, bib]) => {
  requestNumber += 1;
  clearTimeout(searchTimer);
  error.value = '';

  if (props.modelValue.athlete_id) {
    results.value = [];
    loading.value = false;
    return;
  }

  if (text.length < 2 && !bib) {
    results.value = [...props.initialOptions];
    loading.value = false;
    return;
  }

  const currentRequest = requestNumber;
  loading.value = true;
  searchTimer = window.setTimeout(async () => {
    try {
      const params = new URLSearchParams();
      if (text.length >= 2) params.set('q', text);
      if (bib) params.set('bib', bib);
      const { response, data } = await jsonRequest<{ athletes:AthleteChoice[] }>(`/races/${props.raceId}/athletes/search?${params.toString()}`, { method:'GET' });
      if (currentRequest !== requestNumber) return;
      if (!response.ok) throw new Error('search failed');
      results.value = data.athletes ?? [];
    } catch {
      if (currentRequest === requestNumber) {
        results.value = [];
        error.value = 'Could not search athletes.';
      }
    } finally {
      if (currentRequest === requestNumber) loading.value = false;
    }
  }, 220);
}, { immediate:true });

watch(() => props.initialOptions, options => {
  if (!props.modelValue.athlete_id && !hasLookup.value) results.value = [...options];
});

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
  <div class="rounded-xl border border-outline p-3">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
      <strong>{{ title }}</strong>
      <span class="text-xs muted">Existing athletes are found automatically.</span>
    </div>

    <div v-if="selected" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3">
      <div class="flex items-start justify-between gap-3">
        <div>
          <span class="text-xs font-bold uppercase tracking-wider text-success">Existing athlete selected</span>
          <strong class="mt-1 block">{{ selected.full_name }}</strong>
          <span class="mt-1 block text-sm muted">{{ selected.email || 'No email' }}<span v-if="selected.club"> · {{ selected.club }}</span></span>
          <span class="mt-1 block text-xs muted">{{ selected.race_count }} previous {{ selected.race_count===1?'race':'races' }}</span>
        </div>
        <button type="button" class="btn-secondary !px-3" @click="clearSelection"><i class="fa-solid fa-rotate" aria-hidden="true"></i>Change</button>
      </div>
    </div>

    <template v-else>
      <div class="grid grid-cols-2 gap-2">
        <label class="label">First name<input :value="modelValue.first_name" class="field" placeholder="First name" required @input="updateField('first_name',$event)"></label>
        <label class="label">Last name<input :value="modelValue.last_name" class="field" placeholder="Last name" required @input="updateField('last_name',$event)"></label>
        <label class="label col-span-2">Email (optional)<input :value="modelValue.email" class="field" type="email" placeholder="Email (optional)" @input="updateField('email',$event)"></label>
        <label class="label col-span-2">Club (optional)<input :value="modelValue.club" class="field" placeholder="Club (optional)" @input="updateField('club',$event)"></label>
      </div>

      <p class="mt-2 text-xs muted">
        Recent athlete profiles are shown below. Typing a name or email<span v-if="bibNumber">, or entering bib {{ bibNumber }}</span>, searches all athletes automatically. If you do not select an existing athlete, a new profile is created when you add the participant.
      </p>

      <p v-if="loading" class="mt-3 text-sm muted"><i class="fa-solid fa-spinner fa-spin mr-1" aria-hidden="true"></i>Loading athletes…</p>
      <p v-if="error" class="mt-3 text-sm text-error" role="alert">{{ error }}</p>

      <div v-if="!loading && results.length" class="mt-3 rounded-xl border border-cyan-400/30 bg-canvas p-2">
        <div class="flex items-center justify-between gap-3 px-2 pb-2">
          <p class="text-xs font-bold uppercase tracking-wider text-accent">{{ listTitle }}</p>
          <span class="text-xs muted">{{ results.length }} shown</span>
        </div>
        <div class="max-h-64 space-y-1 overflow-y-auto pr-1">
          <button
            v-for="athlete in results"
            :key="athlete.id"
            type="button"
            class="w-full rounded-xl border border-outline p-3 text-left"
            :class="athlete.already_in_race?'cursor-not-allowed opacity-60':'hover:border-cyan-400 hover:bg-raised'"
            :disabled="athlete.already_in_race"
            @click="selectAthlete(athlete)"
          >
            <span class="flex flex-wrap items-center justify-between gap-2">
              <strong>{{ athlete.full_name }}</strong>
              <span v-if="athlete.already_in_race" class="badge">Already in this race</span>
              <span v-else class="text-xs muted">{{ athlete.race_count }} previous {{ athlete.race_count===1?'race':'races' }}</span>
            </span>
            <span class="mt-1 block text-sm muted">{{ athlete.email || 'No email' }}<span v-if="athlete.club"> · {{ athlete.club }}</span></span>
            <span v-if="athlete.recent_bibs.length" class="mt-1 block text-xs muted">Previous bibs: {{ athlete.recent_bibs.join(', ') }}</span>
            <span v-if="athlete.races.length" class="mt-1 block text-xs muted">Recent races: {{ athlete.races.map(race=>race.name).join(' · ') }}</span>
          </button>
        </div>
      </div>

      <p v-else-if="!loading && !error" class="mt-3 rounded-xl bg-canvas p-3 text-sm muted">
        {{ hasLookup ? 'No existing athlete matches. Complete the details to create a new profile.' : 'No recent athletes are available yet. Complete the details to create the first one.' }}
      </p>
    </template>
  </div>
</template>
