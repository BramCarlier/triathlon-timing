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

const fullName = computed({
  get: () => [props.modelValue.first_name, props.modelValue.last_name].filter(Boolean).join(' ').trim(),
  set: (value:string) => {
    const parts = value.trim().split(/\s+/).filter(Boolean);
    const firstName = parts.shift() ?? '';
    emit('update:modelValue', {
      ...props.modelValue,
      athlete_id: null,
      first_name: firstName,
      last_name: parts.join(' '),
    });
  },
});

const textLookup = computed(() => [
  props.modelValue.first_name,
  props.modelValue.last_name,
  props.modelValue.email,
].map(value => value?.trim()).filter(Boolean).join(' ').trim());

const bibLookup = computed(() => (props.bibNumber ?? '').trim());
const hasLookup = computed(() => textLookup.value.length >= 2 || bibLookup.value.length > 0);
const listTitle = computed(() => hasLookup.value ? 'Matching athletes' : 'Available athletes');

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
  results.value = [...props.initialOptions];
  emit('update:modelValue', {
    ...props.modelValue,
    athlete_id:null,
    first_name:'',
    last_name:'',
    email:'',
    club:'',
  });
};

const updateField = (field:'email'|'club', event:Event) => {
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
        error.value = 'Could not load athletes.';
      }
    } finally {
      if (currentRequest === requestNumber) loading.value = false;
    }
  }, 180);
}, { immediate:true });

watch(() => props.initialOptions, options => {
  if (!props.modelValue.athlete_id && !hasLookup.value) results.value = [...options];
});

onBeforeUnmount(() => clearTimeout(searchTimer));
</script>

<template>
  <div class="rounded-xl border border-outline p-3">
    <strong class="block">{{ title }}</strong>

    <div v-if="selected" class="mt-3 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3">
      <div class="flex items-center justify-between gap-3">
        <div class="min-w-0">
          <span class="text-xs font-bold uppercase tracking-wider text-success">Existing athlete</span>
          <strong class="mt-1 block truncate">{{ selected.full_name }}</strong>
          <span v-if="selected.club" class="mt-1 block text-sm muted">{{ selected.club }}</span>
        </div>
        <button type="button" class="btn-secondary !px-3" @click="clearSelection">
          <i class="fa-solid fa-rotate" aria-hidden="true"></i>Change
        </button>
      </div>
    </div>

    <template v-else>
      <label class="label mt-3">
        Name
        <input v-model="fullName" class="field min-h-12" placeholder="Athlete name" autocomplete="off" required>
      </label>

      <p class="mt-2 text-xs muted">If this athlete already exists, choose them below. Otherwise a new athlete is created automatically.</p>

      <p v-if="loading" class="mt-3 text-sm muted"><i class="fa-solid fa-spinner fa-spin mr-1" aria-hidden="true"></i>Looking for athletes…</p>
      <p v-if="error" class="mt-3 text-sm text-error" role="alert">{{ error }}</p>

      <div v-if="!loading && results.length" class="mt-3 rounded-xl border border-outline bg-canvas p-2">
        <div class="flex items-center justify-between gap-3 px-2 pb-2">
          <p class="text-xs font-bold uppercase tracking-wider text-accent">{{ listTitle }}</p>
          <span class="text-xs muted">{{ results.length }} shown</span>
        </div>
        <div class="max-h-52 space-y-1 overflow-y-auto pr-1">
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
              <span v-if="athlete.already_in_race" class="badge">Already added</span>
              <span v-else-if="athlete.club" class="text-xs muted">{{ athlete.club }}</span>
            </span>
          </button>
        </div>
      </div>

      <p v-else-if="!loading && !error && hasLookup" class="mt-3 rounded-xl bg-canvas p-3 text-sm muted">
        No existing athlete matches. This name will be added as a new athlete.
      </p>

      <details class="mt-3 rounded-xl border border-outline p-3">
        <summary class="cursor-pointer text-sm font-semibold">Optional contact details</summary>
        <div class="mt-3 grid gap-3 sm:grid-cols-2">
          <label class="label">Email<input :value="modelValue.email" class="field" type="email" placeholder="Optional" @input="updateField('email',$event)"></label>
          <label class="label">Club<input :value="modelValue.club" class="field" placeholder="Optional" @input="updateField('club',$event)"></label>
        </div>
      </details>
    </template>
  </div>
</template>
