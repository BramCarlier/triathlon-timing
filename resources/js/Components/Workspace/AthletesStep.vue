<script setup lang="ts">
import { watch } from 'vue';
import { Link, useForm } from '@inertiajs/vue3';
import Icon from '../Icon.vue';
import Bib from '../Bib.vue';
import RaceAthletePicker from '../RaceAthletePicker.vue';
import { tr } from '../../i18n';
import type { Race, StationParticipant } from '../../types';
import type { AthleteChoice } from './types';

/** Step 2: add athletes one by one or import a file. */
const props = defineProps<{
  race: Race;
  participants: StationParticipant[];
  athleteOptions: AthleteChoice[];
}>();

const participantForm = useForm({
  bib_number: '',
  type: 'solo' as 'solo' | 'relay',
  team_name: '',
  category: '',
  members: [{ discipline: 'swim', athlete_id: null as number | null, first_name: '', last_name: '', email: '', club: '' }],
});
watch(
  () => participantForm.type,
  type => {
    participantForm.members =
      type === 'solo'
        ? [{ discipline: 'swim', athlete_id: null, first_name: '', last_name: '', email: '', club: '' }]
        : ['swim', 'bike', 'run'].map(discipline => ({
            discipline,
            athlete_id: null as number | null,
            first_name: '',
            last_name: '',
            email: '',
            club: '',
          }));
  },
);
const addParticipant = () =>
  participantForm.post(`/races/${props.race.id}/participants`, {
    preserveScroll: true,
    onSuccess: () => participantForm.reset(),
  });
const disciplineLabel = (discipline: string) => tr(discipline.charAt(0).toUpperCase() + discipline.slice(1));
</script>

<template>
  <div class="grid gap-5" :class="!race.started_at ? 'xl:grid-cols-[.9fr_1.1fr]' : ''">
    <form v-if="!race.started_at" class="rounded-lg border border-outline p-4" @submit.prevent="addParticipant">
      <h3 class="font-bold">{{ $t('Add athlete') }}</h3>
      <p class="mt-1 text-sm muted">{{ $t('For a normal solo athlete, just enter the name. Bibs and everything else are optional.') }}</p>

      <label class="label mt-4">
        {{ $t('Bib number') }}
        <span class="font-normal muted">{{ $t('(optional)') }}</span>
        <input v-model="participantForm.bib_number" class="field" maxlength="32" :placeholder="$t('Leave blank if you do not use bibs')" />
      </label>

      <details class="mt-4 rounded-md border border-outline p-3">
        <summary class="cursor-pointer text-sm font-semibold">{{ $t('Relay, category & other race options') }}</summary>
        <div class="mt-3 grid gap-3">
          <label class="label">
            {{ $t('Entry type') }}
            <select v-model="participantForm.type" class="field">
              <option value="solo">{{ $t('Solo athlete') }}</option>
              <option value="relay">{{ $t('3-person relay') }}</option>
            </select>
          </label>
          <label v-if="participantForm.type === 'relay'" class="label">
            {{ $t('Team name') }}
            <input v-model="participantForm.team_name" class="field" required />
          </label>
          <label class="label">
            {{ $t('Category') }}
            <span class="font-normal muted">{{ $t('(optional)') }}</span>
            <input v-model="participantForm.category" class="field" />
          </label>
        </div>
      </details>

      <div class="mt-4 space-y-3">
        <RaceAthletePicker
          v-for="(member, index) in participantForm.members"
          :key="member.discipline"
          v-model="participantForm.members[index]"
          :race-id="race.id"
          :bib-number="participantForm.bib_number"
          :initial-options="athleteOptions"
          :title="participantForm.type === 'solo' ? $t('Athlete name') : disciplineLabel(member.discipline)"
        />
      </div>
      <p v-if="Object.keys(participantForm.errors).length" class="mt-3 text-sm text-error">
        {{ Object.values(participantForm.errors)[0] }}
      </p>
      <div class="mt-4 flex flex-wrap gap-2">
        <button class="btn-primary" :disabled="participantForm.processing">
          <Icon name="user-plus" />
          {{ $t('Add athlete') }}
        </button>
        <Link :href="`/races/${race.id}/participants/import`" class="btn-secondary">
          <Icon name="file-import" />
          {{ $t('Import file') }}
        </Link>
      </div>
    </form>

    <section class="rounded-lg border border-outline overflow-hidden">
      <div class="border-b border-outline p-4">
        <h3 class="font-bold">{{ $t('Added athletes & teams') }}</h3>
        <p class="mt-1 text-sm muted">{{ $t('Use Edit to add or change a bib number and update athlete details.') }}</p>
      </div>
      <div v-if="participants.length" class="divide-y divide-outline">
        <div
          v-for="participant in participants.slice(0, 10)"
          :key="participant.id"
          class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-x-3 gap-y-1 p-3 sm:grid-cols-[auto_minmax(0,1fr)_auto] sm:p-4"
        >
          <Bib :bib="participant.bib_number" small class="self-center" />
          <div class="min-w-0">
            <strong class="block break-words text-sm [overflow-wrap:anywhere] sm:text-base">{{ participant.name }}</strong>
            <span class="text-xs muted">{{ participant.type === 'relay' ? $t('Relay team') : $t('Solo athlete') }}</span>
          </div>
          <Link
            :href="`/races/${race.id}/participants/${participant.id}/edit`"
            class="btn-secondary col-start-2 justify-self-start !px-3 !py-1.5 !text-xs sm:col-start-3 sm:row-start-1 sm:self-center"
          >
            <Icon name="pen-to-square" />
            {{ $t('Edit') }}
          </Link>
        </div>
      </div>
      <div v-else class="p-6 text-center muted">{{ $t('No athletes or teams yet.') }}</div>
      <div v-if="participants.length > 10" class="border-t border-outline p-4">
        <Link :href="`/races/${race.id}/participants`" class="font-semibold text-accent underline">
          {{ $t('View all') }} {{ participants.length }}
        </Link>
      </div>
    </section>
  </div>
</template>
