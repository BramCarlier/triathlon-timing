<script setup lang="ts">
import type { Checkpoint } from '../../types';
import { checkpointDistanceText } from '../../checkpointDistance';
import { Head, Link, router } from '@inertiajs/vue3';
import { onMounted, onBeforeUnmount } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import RaceClock from '../../Components/RaceClock.vue';
import { formatDuration, bibLabel } from '../../lib';
interface Athlete { id:number; full_name:string; first_name:string; last_name:string }
interface Timing { id:number; elapsed_ms:number; checkpoint:Checkpoint }
interface Member { id:number; discipline:string; athlete:Athlete }
interface Entry { id:number; bib_number:string|null; type:string; team_name?:string; race:{settings?:Record<string,unknown>;id:number;name:string;event_date:string;status:string;started_at?:string|null;finished_at?:string|null}; members:Member[]; timings:Timing[] }
const props = defineProps<{ athlete:Athlete; entries:Entry[]; serverNow:string }>();
const raceIds = [...new Set(props.entries.map(entry => entry.race.id))];
let refreshTimer: number | undefined;
onMounted(() => {
  const echo = (window as any).Echo;
  for (const id of raceIds) echo?.private(`race.${id}`)
    .listen('.race.started', () => router.reload({only:['entries','serverNow']}))
    .listen('.race.finished', () => router.reload({only:['entries','serverNow']}));
  refreshTimer = window.setInterval(() => router.reload({only:['entries','serverNow']}), 30000);
});
onBeforeUnmount(() => {
  if (refreshTimer) window.clearInterval(refreshTimer);
  const echo = (window as any).Echo;
  for (const id of raceIds) echo?.leave(`race.${id}`);
});
</script>
<template><Head title="My race"/><AppLayout :title="`Welcome, ${athlete.first_name}`"><div v-if="entries.length" class="space-y-5"><section v-for="entry in entries" :key="entry.id" class="panel-pad"><div class="flex flex-col gap-4 sm:flex-row sm:items-start sm:justify-between"><div><span class="badge">{{ entry.race.status }}</span><h2 class="mt-2 text-xl font-bold">{{ entry.race.name }}</h2><div class="muted">{{ entry.race.event_date }} · {{ bibLabel(entry.bib_number) }}</div><div v-if="entry.type==='relay'" class="mt-3 rounded-xl bg-canvas p-3"><div class="text-xs font-semibold uppercase tracking-wider text-muted">Your team</div><div class="mt-2 flex flex-wrap gap-x-4 gap-y-1 text-sm"><span v-for="member in entry.members" :key="member.id"><strong class="capitalize">{{ member.discipline }}:</strong> {{ member.athlete.full_name }}</span></div></div></div><div v-if="entry.race.started_at" class="rounded-xl bg-canvas p-4"><div class="mb-1 text-xs uppercase tracking-widest text-accent">Race clock</div><RaceClock :started-at="entry.race.started_at" :finished-at="entry.race.finished_at" :server-now="serverNow" compact/></div></div><div class="mt-5 grid gap-2 sm:grid-cols-2 lg:grid-cols-4"><div v-for="timing in entry.timings" :key="timing.id" class="rounded-xl border border-outline bg-canvas p-4"><div class="text-sm muted">{{ timing.checkpoint.name }}</div><div class="mt-1 text-xs muted">{{ checkpointDistanceText(entry.race,timing.checkpoint) }}</div><div class="mt-1 font-mono text-xl font-bold">{{ formatDuration(timing.elapsed_ms,2) }}</div></div><div v-if="!entry.timings.length" class="rounded-xl border border-outline p-4 muted">No checkpoint times yet.</div></div><Link :href="`/races/${entry.race.id}/results`" class="btn-secondary mt-5">View full results</Link></section></div><div v-else class="panel-pad text-center"><h2 class="text-lg font-bold">No race entry linked yet</h2><p class="mt-2 muted">Ask the race organizer to link this login to your athlete profile.</p></div></AppLayout></template>
