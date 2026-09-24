<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import { formatDuration, bibLabel } from '../../lib';
import type { Checkpoint, Race } from '../../types';

interface Timing { id:number; elapsed_ms:number; recorded_at:string; entry:{bib_number:string|null;display_name:string}; checkpoint:{name:string}; operator?:{name:string} }
interface Presence { id:number; pending_count:number; last_seen_at:string; user?:{name:string}; checkpoint?:{name:string} }
interface EntryOption { id:number; bib_number:string|null; name:string; timings:Array<{checkpoint_id:number;elapsed_ms:number}> }

const props = defineProps<{ race: Race & {checkpoints:Checkpoint[]; entries_count:number}; recentTimings:Timing[]; presence:Presence[]; completedCount:number; entries:EntryOption[] }>();

const correction = useForm({
  entry_id: props.entries[0]?.id ?? null as number|null,
  checkpoint_id: props.race.checkpoints.find(cp=>cp.kind!=='start')?.id ?? null as number|null,
  hours:0,
  minutes:0,
  seconds:0,
  millis:0,
  notes:'',
});
const confirmingCorrection=ref(false);
const oldTiming=computed(()=>props.entries.find(entry=>entry.id===Number(correction.entry_id))?.timings.find(timing=>timing.checkpoint_id===Number(correction.checkpoint_id))?.elapsed_ms);
const proposedTime=computed(()=>Number(correction.hours)*3600000+Number(correction.minutes)*60000+Number(correction.seconds)*1000+Number(correction.millis));
const correctionSummary=computed(()=>`${props.entries.find(entry=>entry.id===Number(correction.entry_id))?.name} · ${props.race.checkpoints.find(cp=>cp.id===Number(correction.checkpoint_id))?.name}: ${oldTiming.value==null?'No recorded time':formatDuration(oldTiming.value,3)} → ${formatDuration(proposedTime.value,3)}. The previous record remains in the audit history.`);
const submitCorrection = () => {
  correction.transform(data => ({
    entry_id:data.entry_id,
    checkpoint_id:data.checkpoint_id,
    elapsed_ms:Math.max(0,Number(data.hours)*3600000+Number(data.minutes)*60000+Number(data.seconds)*1000+Number(data.millis)),
    notes:data.notes,
  })).post(`/races/${props.race.id}/corrections`, {
    preserveScroll:true,
    onSuccess:()=>{confirmingCorrection.value=false;correction.hours=0;correction.minutes=0;correction.seconds=0;correction.millis=0;correction.notes='';},
  });
};

const channelName=`race.${props.race.id}`;
useRaceRefresh(()=>['presence','race','recentTimings','completedCount']);
onMounted(()=>{
  const echo=(window as any).Echo;
  if(echo) echo.private(channelName)
    .listen('.timing.recorded',()=>router.reload({only:['recentTimings','completedCount','presence']}))
    .listen('.timing.voided',()=>router.reload({only:['recentTimings','completedCount','presence']}))
    .listen('.race.finished',()=>router.reload({only:['race','recentTimings','completedCount']}));
});
onBeforeUnmount(()=>{const echo=(window as any).Echo;if(echo)echo.leave(channelName);});
</script>

<template>
  <Head :title="`${race.name} corrections`"/>
  <AppLayout :title="`${race.name} · Corrections & station health`">
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
      <div>
        <p class="font-semibold">Use this page only when the normal Race workspace is not enough.</p>
        <p class="mt-1 text-sm muted">Start and end the race from the Race workspace. Here you can check station connectivity and correct recorded times.</p>
      </div>
      <Link :href="`/races/${race.id}`" class="btn-secondary self-start"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to Race workspace</Link>
    </div>

    <section class="mb-5 grid gap-3 sm:grid-cols-3">
      <div class="panel-pad"><div class="text-2xl font-bold">{{ race.entries_count }}</div><div class="mt-1 text-sm muted">Participants</div></div>
      <div class="panel-pad"><div class="text-2xl font-bold">{{ completedCount }}</div><div class="mt-1 text-sm muted">Finished</div></div>
      <div class="panel-pad"><div class="text-2xl font-bold">{{ presence.length }}</div><div class="mt-1 text-sm muted">Active stations</div></div>
    </section>

    <div class="grid gap-5 xl:grid-cols-[.8fr_1.2fr]">
      <section class="panel-pad">
        <div class="flex items-center justify-between gap-3">
          <div><h2 class="text-lg font-bold">Station health</h2><p class="mt-1 text-sm muted">Stations seen in the last five minutes.</p></div>
          <span class="badge" :data-status="race.finished_at?'finished':race.started_at?'running':race.status">{{ race.finished_at?'Finished':race.started_at?'Live':'Not started' }}</span>
        </div>
        <div v-if="presence.length" class="mt-4 space-y-2">
          <div v-for="item in presence" :key="item.id" class="rounded-xl border border-outline p-3">
            <div class="flex items-center justify-between gap-3">
              <div><strong>{{ item.checkpoint?.name ?? 'Checkpoint' }}</strong><span class="mt-1 block text-sm muted">{{ item.user?.name ?? 'Official' }}</span></div>
              <span class="shrink-0 text-sm font-semibold" :class="item.pending_count?'text-warning':'text-success'">{{ item.pending_count? `${item.pending_count} pending`:'Synced' }}</span>
            </div>
          </div>
        </div>
        <p v-else class="mt-4 rounded-xl bg-canvas p-4 muted">No timing station has checked in during the last five minutes.</p>
      </section>

      <section class="panel-pad">
        <div class="flex flex-col gap-2 sm:flex-row sm:items-start sm:justify-between">
          <div><h2 class="text-lg font-bold">Correct a timing</h2><p class="mt-1 text-sm muted">Use this only for a missed or incorrect checkpoint time. Every correction remains in the audit history.</p></div>
          <span class="badge shrink-0 self-start">Audited</span>
        </div>
        <form class="mt-5 space-y-4" @submit.prevent="confirmingCorrection=true">
          <div class="grid gap-4 sm:grid-cols-2">
            <label class="label">Participant<select v-model="correction.entry_id" class="field" required><option :value="null">Choose participant</option><option v-for="entry in entries" :key="entry.id" :value="entry.id">{{ bibLabel(entry.bib_number) }} · {{ entry.name }}</option></select></label>
            <label class="label">Checkpoint<select v-model="correction.checkpoint_id" class="field" required><option :value="null">Choose checkpoint</option><option v-for="cp in race.checkpoints.filter(c=>c.kind!=='start')" :key="cp.id" :value="cp.id">{{ cp.name }}</option></select></label>
          </div>
          <p class="rounded-xl bg-canvas p-3 text-sm">Current timing: <strong class="font-mono">{{ oldTiming==null?'Not recorded':formatDuration(oldTiming,3) }}</strong></p>
          <fieldset>
            <legend class="label">Correct elapsed time</legend>
            <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
              <label class="label">Hours<input v-model="correction.hours" class="field" type="number" min="0" max="168" required></label>
              <label class="label">Minutes<input v-model="correction.minutes" class="field" type="number" min="0" max="59" required></label>
              <label class="label">Seconds<input v-model="correction.seconds" class="field" type="number" min="0" max="59" required></label>
              <label class="label">Milliseconds<input v-model="correction.millis" class="field" type="number" min="0" max="999" required></label>
            </div>
            <p class="mt-2 text-sm muted">Preview: <strong class="font-mono">{{ formatDuration(proposedTime,3) }}</strong></p>
          </fieldset>
          <label class="label">Reason<input v-model="correction.notes" class="field" placeholder="For example: finish camera review" maxlength="1000" required></label>
          <p v-if="Object.keys(correction.errors).length" class="text-sm text-error">{{ Object.values(correction.errors)[0] }}</p>
          <button class="btn-primary" :disabled="correction.processing || !race.started_at"><i class="fa-solid fa-clipboard-check" aria-hidden="true"></i>Review correction</button>
          <p v-if="!race.started_at" class="text-sm muted">Corrections become available after the race starts.</p>
        </form>
      </section>
    </div>

    <details class="panel-pad mt-5">
      <summary class="font-bold"><i class="fa-solid fa-clock-rotate-left mr-2" aria-hidden="true"></i>Recent timings</summary>
      <div v-if="recentTimings.length" class="mt-3 divide-y divide-outline">
        <div v-for="timing in recentTimings" :key="timing.id" class="grid gap-2 py-3 sm:grid-cols-[minmax(0,1fr)_minmax(0,1fr)_auto] sm:items-center">
          <div><strong>{{ timing.entry.display_name }}</strong><span class="block text-xs muted">{{ bibLabel(timing.entry.bib_number) }}</span></div>
          <div><span class="font-semibold">{{ timing.checkpoint.name }}</span><span class="block text-sm muted">{{ timing.operator?.name }}</span></div>
          <div class="font-mono font-semibold">{{ formatDuration(timing.elapsed_ms,2) }}</div>
        </div>
      </div>
      <p v-else class="mt-3 muted">No timings recorded yet.</p>
    </details>

    <ConfirmDialog v-if="confirmingCorrection" title="Save timing correction?" :message="correctionSummary" confirm-label="Save correction" :busy="correction.processing" @cancel="confirmingCorrection=false" @confirm="submitCorrection">
      <p class="mt-3 text-sm">Reason: {{ correction.notes }}</p>
      <p v-if="Object.keys(correction.errors).length" role="alert" class="mt-3 text-error">{{ Object.values(correction.errors)[0] }}</p>
    </ConfirmDialog>
  </AppLayout>
</template>
