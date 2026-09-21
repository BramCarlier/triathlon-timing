<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import RaceClock from '../../Components/RaceClock.vue';
import { formatDuration } from '../../lib';
import type { Checkpoint, Race } from '../../types';
interface Timing { id:number; elapsed_ms:number; recorded_at:string; entry:{bib_number:string;team_name?:string}; checkpoint:{name:string}; operator?:{name:string} }
interface Presence { id:number; pending_count:number; last_seen_at:string; user?:{name:string}; checkpoint?:{name:string} }
interface EntryOption { id:number; bib_number:string; name:string }
const props = defineProps<{ race: Race & {checkpoints:Checkpoint[]; entries_count:number}; recentTimings:Timing[]; presence:Presence[]; completedCount:number; entries:EntryOption[]; serverNow:string }>();
const start = () => { if (confirm('Start the shared race clock now? This action is intentionally one-way.')) router.post(`/races/${props.race.id}/start`); };
const finish = () => { if (confirm('Mark the race as finished? Existing timings remain editable.')) router.post(`/races/${props.race.id}/finish`); };

const correction = useForm({ entry_id: props.entries[0]?.id ?? null as number|null, checkpoint_id: props.race.checkpoints.find(cp=>cp.kind!=='start')?.id ?? null as number|null, hours:0, minutes:0, seconds:0, millis:0, notes:'' });
const submitCorrection = () => {
  correction.transform(data => ({
    entry_id: data.entry_id,
    checkpoint_id: data.checkpoint_id,
    elapsed_ms: Math.max(0, Number(data.hours)*3600000 + Number(data.minutes)*60000 + Number(data.seconds)*1000 + Number(data.millis)),
    notes: data.notes || 'Manual correction from Race Control',
  })).post(`/races/${props.race.id}/corrections`, { preserveScroll:true, onSuccess:()=>{ correction.hours=0; correction.minutes=0; correction.seconds=0; correction.millis=0; correction.notes=''; } });
};

const channelName = `race.${props.race.id}`;
let refreshTimer:number|undefined;
onMounted(() => {
  const echo = (window as any).Echo;
  if (echo) echo.private(channelName)
    .listen('.timing.recorded', () => router.reload({only:['recentTimings','completedCount']}))
    .listen('.timing.voided', () => router.reload({only:['recentTimings','completedCount']}))
    .listen('.race.started', () => router.reload({only:['race','serverNow']}))
    .listen('.race.finished', () => router.reload({only:['race','serverNow']}));
  refreshTimer = window.setInterval(() => router.reload({only:['presence']}), 30000);
});
onBeforeUnmount(() => { if(refreshTimer) clearInterval(refreshTimer); const echo=(window as any).Echo; if(echo) echo.leave(channelName); });

</script>
<template><Head :title="`${race.name} control`"/><AppLayout :title="`${race.name} · Race control`">
  <div class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
    <section class="panel-pad"><div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"><div><div class="mb-2 text-xs font-semibold uppercase tracking-[.2em] text-cyan-300">Shared race clock</div><RaceClock :started-at="race.started_at" :server-now="serverNow"/></div><div class="flex gap-2"><button v-if="!race.started_at" class="btn-primary min-w-32" @click="start">Start race</button><button v-else-if="!race.finished_at" class="btn-danger" @click="finish">Finish race</button><span v-else class="badge">Finished</span></div></div><div class="mt-6 grid grid-cols-3 gap-3"><div class="rounded-xl bg-slate-950 p-4"><div class="text-2xl font-bold">{{ race.entries_count }}</div><div class="muted text-sm">Entries</div></div><div class="rounded-xl bg-slate-950 p-4"><div class="text-2xl font-bold">{{ completedCount }}</div><div class="muted text-sm">Finished</div></div><div class="rounded-xl bg-slate-950 p-4"><div class="text-2xl font-bold">{{ presence.length }}</div><div class="muted text-sm">Active stations</div></div></div><div class="mt-5 grid gap-2 sm:grid-cols-3"><Link :href="`/races/${race.id}/station`" class="btn-primary">Open station</Link><Link :href="`/races/${race.id}/participants`" class="btn-secondary">Participants</Link><Link :href="`/races/${race.id}/results`" class="btn-secondary">Results</Link></div></section>
    <section class="panel-pad"><h2 class="mb-3 font-bold">Checkpoint health</h2><div v-if="presence.length" class="space-y-2"><div v-for="item in presence" :key="item.id" class="rounded-xl border border-slate-800 p-3"><div class="flex justify-between gap-3"><div><div class="font-semibold">{{ item.checkpoint?.name ?? 'Checkpoint' }}</div><div class="muted text-sm">{{ item.user?.name ?? 'Organizer' }}</div></div><span :class="item.pending_count ? 'text-amber-300' : 'text-emerald-300'" class="text-sm font-semibold">{{ item.pending_count ? `${item.pending_count} pending` : 'Synced' }}</span></div></div></div><p v-else class="muted">No station has checked in during the last five minutes.</p></section>
  </div>
  <section class="panel-pad mt-5"><div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"><div><h2 class="text-lg font-bold">Manual timing correction</h2><p class="mt-1 muted text-sm">Use this for a missed or incorrect checkpoint. Any existing active timing at the same checkpoint is preserved as a voided audit record and replaced.</p></div><span class="badge">audited</span></div><form class="mt-4 grid gap-3 lg:grid-cols-[1.2fr_1.2fr_repeat(4,.45fr)_1fr_auto]" @submit.prevent="submitCorrection"><select v-model="correction.entry_id" class="field" required><option :value="null">Participant…</option><option v-for="entry in entries" :key="entry.id" :value="entry.id">#{{ entry.bib_number }} · {{ entry.name }}</option></select><select v-model="correction.checkpoint_id" class="field" required><option :value="null">Checkpoint…</option><option v-for="cp in race.checkpoints.filter(c=>c.kind!=='start')" :key="cp.id" :value="cp.id">{{ cp.name }}</option></select><input v-model="correction.hours" class="field" type="number" min="0" placeholder="hh" title="Hours"><input v-model="correction.minutes" class="field" type="number" min="0" max="59" placeholder="mm" title="Minutes"><input v-model="correction.seconds" class="field" type="number" min="0" max="59" placeholder="ss" title="Seconds"><input v-model="correction.millis" class="field" type="number" min="0" max="999" placeholder="ms" title="Milliseconds"><input v-model="correction.notes" class="field" placeholder="Reason / note"><button class="btn-primary" :disabled="correction.processing">Save correction</button></form><p v-if="Object.keys(correction.errors).length" class="mt-2 text-sm text-red-300">{{ Object.values(correction.errors)[0] }}</p></section>
  <section class="panel mt-5 overflow-hidden"><div class="border-b border-slate-800 p-4 font-bold">Recent timings</div><div v-if="recentTimings.length" class="divide-y divide-slate-800"><div v-for="timing in recentTimings" :key="timing.id" class="grid grid-cols-[auto_1fr_auto] items-center gap-4 p-4"><div class="rounded-lg bg-slate-800 px-3 py-2 font-mono font-bold">#{{ timing.entry.bib_number }}</div><div><div class="font-semibold">{{ timing.checkpoint.name }}</div><div class="muted text-sm">{{ timing.operator?.name }}</div></div><div class="font-mono font-semibold">{{ formatDuration(timing.elapsed_ms, true) }}</div></div></div><div v-else class="p-6 text-center muted">No timings recorded yet.</div></section>
</AppLayout></template>
