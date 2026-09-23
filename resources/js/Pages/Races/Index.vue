<script setup lang="ts">
import { usePermissions } from '../../Composables/usePermissions';
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import RaceClock from '../../Components/RaceClock.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { formatDate } from '../../presentation';
import type { Race } from '../../types';

const can=usePermissions();
defineProps<{races:Race[];deletedRaces:Race[];serverNow:string}>();
useRaceRefresh(()=>['races','serverNow']);

const statusLabel=(race:Race)=>{
  if(race.finished_at)return 'Finished';
  if(race.started_at)return 'Live now';
  return race.status==='ready'?'Ready for race day':'Being prepared';
};
</script>

<template>
  <Head title="Races"/>
  <AppLayout title="Races">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="font-semibold">Choose a race and do everything from its Race workspace.</p>
        <p class="mt-1 text-sm muted">Setup, checkpoints, participants, the race clock and normal timing controls are kept together.</p>
      </div>
      <Link v-if="can('races.create')" href="/races/create" class="btn-primary">
        <i class="fa-solid fa-plus" aria-hidden="true"></i>Create race
      </Link>
    </div>

    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="race in races" :key="race.id" class="panel-pad flex flex-col gap-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <span class="badge" :data-status="race.status">{{ statusLabel(race) }}</span>
            <h2 class="mt-3 text-xl font-bold">{{ race.name }}</h2>
            <p class="mt-1 text-sm muted">{{ formatDate(race.event_date) }} · {{ race.entries_count??0 }} participants</p>
          </div>
        </div>
        <div v-if="race.started_at" class="rounded-xl bg-canvas p-4">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-accent">{{ race.finished_at?'Final race time':'Shared race clock' }}</div>
          <RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow" compact/>
        </div>
        <Link :href="`/races/${race.id}`" class="btn-primary mt-auto">
          <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>{{ race.finished_at?'Open finished race':'Open race' }}
        </Link>
      </article>
    </div>

    <section v-if="!races.length" class="panel-pad text-center">
      <h2 class="text-xl font-bold">Your first race starts here</h2>
      <p class="mx-auto mt-3 max-w-lg muted">Create a race to get one guided workspace for setup and race day.</p>
      <Link v-if="can('races.create')" href="/races/create" class="btn-primary mt-5">
        <i class="fa-solid fa-plus" aria-hidden="true"></i>Create your first race
      </Link>
    </section>

    <details v-if="deletedRaces.length" class="panel-pad mt-6">
      <summary class="cursor-pointer font-bold">Deleted races · {{ deletedRaces.length }}</summary>
      <p class="mt-2 text-sm muted">These are hidden from normal race work but can still be restored.</p>
      <div v-for="race in deletedRaces" :key="race.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-outline py-4 last:border-0">
        <div><h3 class="font-semibold">{{ race.name }}</h3><p class="text-sm muted">{{ formatDate(race.event_date) }}</p></div>
        <button class="btn-secondary" @click="router.post(`/races/${race.id}/restore`)">
          <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>Restore
        </button>
      </div>
    </details>
  </AppLayout>
</template>
