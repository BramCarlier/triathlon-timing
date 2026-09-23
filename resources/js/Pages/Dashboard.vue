<script setup lang="ts">
import { usePermissions } from '../Composables/usePermissions';
const can=usePermissions();
import { formatDate } from '../presentation';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '../Layouts/AppLayout.vue';
import RaceClock from '../Components/RaceClock.vue';
import type { Race } from '../types';
const props = defineProps<{ races: Race[]; serverNow: string }>();
const raceIds = props.races.map(race => race.id);
let refreshTimer: number | undefined;
onMounted(() => {
  const echo = (window as any).Echo;
  for (const id of raceIds) echo?.private(`race.${id}`)
    .listen('.race.started', () => router.reload({only:['races','serverNow']}))
    .listen('.race.finished', () => router.reload({only:['races','serverNow']}));
  refreshTimer = window.setInterval(() => router.reload({only:['races','serverNow']}), 30000);
});
onBeforeUnmount(() => {
  if (refreshTimer) window.clearInterval(refreshTimer);
  const echo = (window as any).Echo;
  for (const id of raceIds) echo?.leave(`race.${id}`);
});
</script>
<template>
  <Head title="Dashboard" />
  <AppLayout title="Race dashboard">
    <div class="mb-5 flex justify-end"><Link v-if="can('races.create')" href="/races/create" class="btn-primary"><i class="fa-solid fa-plus" aria-hidden="true"></i>Create race</Link></div>
    <div v-if="races.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="race in races" :key="race.id" class="panel-pad flex flex-col gap-4">
        <div class="flex items-start justify-between gap-3"><div><span class="badge" :data-status="race.status">{{ race.status }}</span><h2 class="mt-2 text-xl font-bold">{{ race.name }}</h2><p class="muted">{{ formatDate(race.event_date) }} · {{ race.timezone }}</p></div><div class="text-right text-sm muted">{{ race.entries_count ?? 0 }} entries</div></div>
        <div v-if="race.started_at" class="rounded-xl bg-canvas p-4"><div class="mb-1 text-xs font-semibold uppercase tracking-widest text-accent">Race time</div><RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow" compact /></div>
        <div class="mt-auto grid grid-cols-2 gap-2"><Link v-if="can('timings.record')" :href="`/races/${race.id}/station`" class="btn-primary"><i class="fa-solid fa-stopwatch" aria-hidden="true"></i>Timing station</Link><Link v-if="can('races.control')" :href="`/races/${race.id}/control`" class="btn-secondary"><i class="fa-solid fa-sliders" aria-hidden="true"></i>Race control</Link><Link v-if="can('participants.manage')" :href="`/races/${race.id}/participants`" class="btn-secondary"><i class="fa-solid fa-users" aria-hidden="true"></i>Participants</Link><Link :href="`/races/${race.id}/results`" class="btn-secondary"><i class="fa-solid fa-trophy" aria-hidden="true"></i>Results</Link></div>
      </article>
    </div>
    <div v-else class="panel-pad text-center"><h2 class="text-xl font-semibold">No races yet</h2><p class="mt-2 muted">Create the first race to configure checkpoints and import participants.</p></div>
  </AppLayout>
</template>
