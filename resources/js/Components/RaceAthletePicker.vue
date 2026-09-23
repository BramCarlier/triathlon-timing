<script setup lang="ts">
import { ref, watch, onBeforeUnmount } from 'vue';
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
}>();

const emit = defineEmits<{
  'update:modelValue': [value: AthleteMemberValue];
}>();

const mode = ref<'new'|'existing'>(props.modelValue.athlete_id ? 'existing' : 'new');
const query = ref('');
const results = ref<AthleteChoice[]>([]);
const selected = ref<AthleteChoice|null>(null);
const loading = ref(false);
const error = ref('');
let searchTimer:number|undefined;
let requestNumber = 0;

const setMode = (value:'new'|'existing') => {
  mode.value = value;
  error.value = '';
  if (value === 'new') {
    selected.value = null;
    query.value = '';
    results.value = [];
    emit('update:modelValue', { ...props.modelValue, athlete_id:null });
  }
};

const selectAthlete = (athlete:AthleteChoice) => {
  if (athlete.already_in_race) return;
  selected.value = athlete;
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
  emit('update:modelValue', { ...props.modelValue, athlete_id:null, first_name:'', last_name:'', email:'', club:'' });
};

const updateField = (field:'first_name'|'last_name'|'email'|'club', event:Event) => {
  const target = event.target as HTMLInputElement;
  emit('update:modelValue', { ...props.modelValue, [field]:target.value });
};

watch(() => props.modelValue.athlete_id, athleteId => {
  if (!athleteId) selected.value = null;
});

watch(query, value => {
  requestNumber += 1;
  clearTimeout(searchTimer);
  const term = value.trim();
  if (mode.value !== 'existing' || term.length < 2) {
    results.value = [];
    loading.value = false;
    error.value = '';
    return;
  }
  const currentRequest = requestNumber;
  loading.value = true;
  error.value = '';
  searchTimer = window.setTimeout(async () => {
    try {
      const { response, data } = await jsonRequest<{ athletes:AthleteChoice[] }>(`/races/${props.raceId}/athletes/search?q=${encodeURIComponent(term)}`, { method:'GET' });
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
});

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
  <div class="rounded-xl border border-outline p-3">
    <div class="mb-3 flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
      <strong>{{ title }}</strong>
      <span class="text-xs muted">Reuse an athlete to keep all race results together.</span>
    </div>

    <div class="grid grid-cols-2 gap-2">
      <button type="button" class="rounded-xl border p-3 text-sm font-semibold" :class="mode==='new'?'border-cyan-400 bg-cyan-400/10':'border-outline'" @click="setMode('new')">
        <i class="fa-solid fa-user-plus mr-1" aria-hidden="true"></i>New athlete
      </button>
      <button type="button" class="rounded-xl border p-3 text-sm font-semibold" :class="mode==='existing'?'border-cyan-400 bg-cyan-400/10':'border-outline'" @click="setMode('existing')">
        <i class="fa-solid fa-magnifying-glass mr-1" aria-hidden="true"></i>Existing athlete
      </button>
    </div>

    <div v-if="mode==='new'" class="mt-4 grid grid-cols-2 gap-2">
      <label class="label">First name<input :value="modelValue.first_name" class="field" placeholder="First name" required @input="updateField('first_name',$event)"></label>
      <label class="label">Last name<input :value="modelValue.last_name" class="field" placeholder="Last name" required @input="updateField('last_name',$event)"></label>
      <label class="label col-span-2">Email (optional)<input :value="modelValue.email" class="field" type="email" placeholder="Email (optional)" @input="updateField('email',$event)"></label>
      <label class="label col-span-2">Club (optional)<input :value="modelValue.club" class="field" placeholder="Club (optional)" @input="updateField('club',$event)"></label>
      <p class="col-span-2 text-xs muted">If this email already belongs to an athlete, the existing profile is reused automatically.</p>
    </div>

    <div v-else class="mt-4">
      <div v-if="selected" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3">
        <div class="flex items-start justify-between gap-3">
          <div>
            <strong class="block">{{ selected.full_name }}</strong>
            <span class="mt-1 block text-sm muted">{{ selected.email || 'No email' }}<span v-if="selected.club"> · {{ selected.club }}</span></span>
            <span class="mt-1 block text-xs muted">{{ selected.race_count }} previous {{ selected.race_count===1?'race':'races' }}</span>
          </div>
          <button type="button" class="btn-secondary !px-3" @click="clearSelection"><i class="fa-solid fa-rotate" aria-hidden="true"></i>Change</button>
        </div>
      </div>
      <template v-else>
        <label class="label">Search by name or email<input v-model="query" class="field" type="search" autocomplete="off" placeholder="Start typing an athlete name or email"></label>
        <p v-if="query.trim().length<2" class="mt-2 text-xs muted">Enter at least 2 characters.</p>
        <p v-else-if="loading" class="mt-2 text-sm muted"><i class="fa-solid fa-spinner fa-spin mr-1" aria-hidden="true"></i>Searching athletes…</p>
        <p v-if="error" class="mt-2 text-sm text-error" role="alert">{{ error }}</p>
        <div v-if="!loading && results.length" class="mt-3 space-y-2">
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
            <span v-if="athlete.races.length" class="mt-1 block text-xs muted">Recent: {{ athlete.races.map(race=>race.name).join(' · ') }}</span>
          </button>
        </div>
        <p v-else-if="!loading && query.trim().length>=2 && !error" class="mt-3 text-sm muted">No matching athlete found. Switch to “New athlete” to create one.</p>
      </template>
    </div>
  </div>
</template>
