<script setup lang="ts">
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
    <div class="mb-5 flex justify-end"><Link href="/races/create" class="btn-primary">Create race</Link></div>
    <div v-if="races.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="race in races" :key="race.id" class="panel-pad flex flex-col gap-4">
        <div class="flex items-start justify-between gap-3"><div><span class="badge">{{ race.status }}</span><h2 class="mt-2 text-xl font-bold">{{ race.name }}</h2><p class="muted">{{ race.event_date }} · {{ race.timezone }}</p></div><div class="text-right text-sm muted">{{ race.entries_count ?? 0 }} entries</div></div>
        <div v-if="race.started_at" class="rounded-xl bg-slate-950 p-4"><div class="mb-1 text-xs font-semibold uppercase tracking-widest text-cyan-300">Race time</div><RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow" compact /></div>
        <div class="mt-auto grid grid-cols-2 gap-2"><Link :href="`/races/${race.id}/station`" class="btn-primary">Timing station</Link><Link :href="`/races/${race.id}/control`" class="btn-secondary">Race control</Link><Link :href="`/races/${race.id}/participants`" class="btn-secondary">Participants</Link><Link :href="`/races/${race.id}/results`" class="btn-secondary">Results</Link></div>
      </article>
    </div>
    <div v-else class="panel-pad text-center"><h2 class="text-xl font-semibold">No races yet</h2><p class="mt-2 muted">Create the first race to configure checkpoints and import participants.</p></div>
  </AppLayout>
</template>
