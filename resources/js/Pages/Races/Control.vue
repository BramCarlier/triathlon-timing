<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { onBeforeUnmount, onMounted, ref, computed } from 'vue';
import RaceChecklist from '../../Components/RaceChecklist.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import RaceClock from '../../Components/RaceClock.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import LiveUpdatesStatus from '../../Components/LiveUpdatesStatus.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import { formatDuration, bibLabel } from '../../lib';
import type { Checkpoint, Race } from '../../types';
interface Timing { id:number; elapsed_ms:number; recorded_at:string; entry:{bib_number:string|null;team_name?:string;display_name:string}; checkpoint:{name:string}; operator?:{name:string} }
interface Presence { id:number; pending_count:number; last_seen_at:string; user?:{name:string}; checkpoint?:{name:string} }
interface EntryOption { id:number; bib_number:string|null; name:string; timings:Array<{checkpoint_id:number;elapsed_ms:number}> }
const props = defineProps<{ race: Race & {checkpoints:Checkpoint[]; entries_count:number}; recentTimings:Timing[]; presence:Presence[]; completedCount:number; entries:EntryOption[]; serverNow:string }>();
const pendingAction = ref<'start' | 'finish' | null>(null);
const clockForm = useForm({});
const changeClock = () => {
  if (!pendingAction.value) return;
  clockForm.post(`/races/${props.race.id}/${pendingAction.value}`, {
    preserveScroll: true,
    onSuccess: () => { pendingAction.value = null; },
  });
};

const correction = useForm({ entry_id: props.entries[0]?.id ?? null as number|null, checkpoint_id: props.race.checkpoints.find(cp=>cp.kind!=='start')?.id ?? null as number|null, hours:0, minutes:0, seconds:0, millis:0, notes:'' });
const confirmingCorrection=ref(false);
const oldTiming=computed(()=>props.entries.find(entry=>entry.id===Number(correction.entry_id))?.timings.find(timing=>timing.checkpoint_id===Number(correction.checkpoint_id))?.elapsed_ms);
const proposedTime=computed(()=>Number(correction.hours)*3600000+Number(correction.minutes)*60000+Number(correction.seconds)*1000+Number(correction.millis));
const correctionSummary=computed(()=>`${props.entries.find(entry=>entry.id===Number(correction.entry_id))?.name} · ${props.race.checkpoints.find(cp=>cp.id===Number(correction.checkpoint_id))?.name}: ${oldTiming.value==null?'No recorded time':formatDuration(oldTiming.value,3)} → ${formatDuration(proposedTime.value,3)}. The previous record remains in the audit history.`);
const submitCorrection = () => {
  correction.transform(data => ({
    entry_id: data.entry_id,
    checkpoint_id: data.checkpoint_id,
    elapsed_ms: Math.max(0, Number(data.hours)*3600000 + Number(data.minutes)*60000 + Number(data.seconds)*1000 + Number(data.millis)),
    notes: data.notes || 'Manual correction from Race Control',
  })).post(`/races/${props.race.id}/corrections`, { preserveScroll:true, onSuccess:()=>{ confirmingCorrection.value=false; correction.hours=0; correction.minutes=0; correction.seconds=0; correction.millis=0; correction.notes=''; } });
};

const channelName = `race.${props.race.id}`;
useRaceRefresh(() => ['presence','race','serverNow','recentTimings','completedCount']);
onMounted(() => {
  const echo = (window as any).Echo;
  if (echo) echo.private(channelName)
    .listen('.timing.recorded', () => router.reload({only:['recentTimings','completedCount']}))
    .listen('.timing.voided', () => router.reload({only:['recentTimings','completedCount']}))
    .listen('.race.started', () => router.reload({only:['race','serverNow']}))
    .listen('.race.finished', () => router.reload({only:['race','serverNow']}));
});
onBeforeUnmount(() => { const echo=(window as any).Echo; if(echo) echo.leave(channelName); });

</script>
<template><Head :title="`${race.name} control`"/><AppLayout :title="`${race.name} · Race control`">
  <RaceChecklist :race="race"/><div v-if="race.finished_at" class="panel-pad mb-5 flex flex-wrap items-center justify-between gap-3"><div><h2 class="font-bold">Race completed</h2><p class="mt-1 muted">The clock is stopped. Review results or correct a missed timing below.</p></div><Link :href="`/races/${race.id}/results`" class="btn-primary">View results</Link></div>
  <div class="grid gap-5 xl:grid-cols-[1.2fr_.8fr]">
    <section class="panel-pad"><div class="flex flex-col gap-5 sm:flex-row sm:items-center sm:justify-between"><div><div class="mb-2 text-xs font-semibold uppercase tracking-[.2em] text-accent">Shared race clock</div><RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow"/><LiveUpdatesStatus :race-id="race.id"/></div><div class="flex gap-2"><button v-if="!race.started_at" class="btn-primary min-w-32" @click="pendingAction='start'">Start race</button><button v-else-if="!race.finished_at" class="btn-danger" @click="pendingAction='finish'">Finish race</button><span v-else class="badge">Finished</span></div></div><div class="mt-6 grid grid-cols-3 gap-3"><div class="rounded-xl bg-canvas p-4"><div class="text-2xl font-bold">{{ race.entries_count }}</div><div class="muted text-sm">Entries</div></div><div class="rounded-xl bg-canvas p-4"><div class="text-2xl font-bold">{{ completedCount }}</div><div class="muted text-sm">Finished</div></div><div class="rounded-xl bg-canvas p-4"><div class="text-2xl font-bold">{{ presence.length }}</div><div class="muted text-sm">Active stations</div></div></div></section>
    <section class="panel-pad"><h2 class="mb-3 font-bold">Checkpoint health</h2><div v-if="presence.length" class="space-y-2"><div v-for="item in presence" :key="item.id" class="rounded-xl border border-outline p-3"><div class="flex justify-between gap-3"><div><div class="font-semibold">{{ item.checkpoint?.name ?? 'Checkpoint' }}</div><div class="muted text-sm">{{ item.user?.name ?? 'Organizer' }}</div></div><span :class="item.pending_count ? 'text-warning' : 'text-success'" class="text-sm font-semibold">{{ item.pending_count ? `${item.pending_count} pending` : 'Synced' }}</span></div></div></div><p v-else class="muted">No station has checked in during the last five minutes.</p></section>
  </div>
  <section class="panel-pad mt-5"><div class="flex flex-col gap-2 sm:flex-row sm:items-end sm:justify-between"><div><h2 class="text-lg font-bold">Manual timing correction</h2><p class="mt-1 muted text-sm">Use this for a missed or incorrect checkpoint. Any existing active timing at the same checkpoint is preserved as a voided audit record and replaced.</p></div><span class="badge">audited</span></div><form class="mt-4 space-y-4" @submit.prevent="confirmingCorrection=true"><div class="grid gap-4 sm:grid-cols-2"><label class="label">Participant<select v-model="correction.entry_id" class="field" required><option :value="null">Choose participant</option><option v-for="entry in entries" :key="entry.id" :value="entry.id">{{ bibLabel(entry.bib_number) }} · {{ entry.name }}</option></select></label><label class="label">Checkpoint<select v-model="correction.checkpoint_id" class="field" required><option :value="null">Choose checkpoint</option><option v-for="cp in race.checkpoints.filter(c=>c.kind!=='start')" :key="cp.id" :value="cp.id">{{ cp.name }}</option></select></label></div><p class="rounded-xl bg-canvas p-3 text-sm">Current timing: <strong class="font-mono">{{ oldTiming==null?'Not recorded':formatDuration(oldTiming,3) }}</strong></p><fieldset><legend class="label">Replacement elapsed time from race start</legend><div class="grid grid-cols-2 gap-3 sm:grid-cols-4"><label class="label">Hours<input v-model="correction.hours" class="field" type="number" min="0" max="168" required></label><label class="label">Minutes<input v-model="correction.minutes" class="field" type="number" min="0" max="59" required></label><label class="label">Seconds<input v-model="correction.seconds" class="field" type="number" min="0" max="59" required></label><label class="label">Milliseconds<input v-model="correction.millis" class="field" type="number" min="0" max="999" required></label></div><p class="mt-2 text-sm muted">Example: 23.450 seconds = 23 seconds and 450 milliseconds. Preview: <strong class="font-mono">{{ formatDuration(proposedTime,3) }}</strong></p></fieldset><label class="label">Reason for correction<input v-model="correction.notes" class="field" placeholder="For example: participant missed at the finish" maxlength="1000" required></label><button class="btn-primary" :disabled="correction.processing || !race.started_at">Review correction</button><p v-if="!race.started_at" class="text-sm muted">Corrections are available after the race starts.</p></form><p v-if="Object.keys(correction.errors).length" class="mt-2 text-sm text-error">{{ Object.values(correction.errors)[0] }}</p></section>
  <section class="panel mt-5 overflow-hidden"><div class="border-b border-outline p-4 font-bold">Recent timings</div><div v-if="recentTimings.length" class="divide-y divide-outline"><div v-for="timing in recentTimings" :key="timing.id" class="grid grid-cols-[auto_1fr_auto] items-center gap-4 p-4"><div class="rounded-lg bg-raised px-3 py-2 font-mono font-bold">{{ timing.entry.display_name }}<span class="block text-xs muted">{{ bibLabel(timing.entry.bib_number) }}</span></div><div><div class="font-semibold">{{ timing.checkpoint.name }}</div><div class="muted text-sm">{{ timing.operator?.name }}</div></div><div class="font-mono font-semibold">{{ formatDuration(timing.elapsed_ms,2) }}</div></div></div><div v-else class="p-6 text-center muted">No timings recorded yet.</div></section>
  <ConfirmDialog v-if="confirmingCorrection" title="Save timing correction?" :message="correctionSummary" confirm-label="Save correction" :busy="correction.processing" @cancel="confirmingCorrection=false" @confirm="submitCorrection"><p class="mt-3 text-sm">Reason: {{ correction.notes }}</p><p v-if="Object.keys(correction.errors).length" role="alert" class="mt-3 text-error">{{ Object.values(correction.errors)[0] }}</p></ConfirmDialog>
  <ConfirmDialog v-if="pendingAction" :title="pendingAction==='start' ? 'Start race?' : 'Finish race?'"
    :message="pendingAction==='start' ? 'Start the shared clock for all stations now? The start time cannot be reset.' : 'Stop the shared clock for all stations? Existing timings can still be corrected in Race control.'"
    :confirm-label="pendingAction==='start' ? 'Confirm start' : 'Confirm finish'" :busy="clockForm.processing"
    @cancel="pendingAction=null; clockForm.clearErrors()" @confirm="changeClock">
    <p v-if="Object.keys(clockForm.errors).length" class="mt-3 text-error">{{ Object.values(clockForm.errors)[0] }}</p>
  </ConfirmDialog>
</AppLayout></template>
