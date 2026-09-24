<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { Head, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import RaceClock from '../../Components/RaceClock.vue';
import { bibLabel, formatDuration, jsonRequest, uuid } from '../../lib';
import type { Checkpoint, StationParticipant } from '../../types';

interface PublicRace {
  id:number;
  name:string;
  event_date:string;
  status:string;
  started_at?:string|null;
  finished_at?:string|null;
  settings?:Record<string,unknown>;
  checkpoints:Checkpoint[];
  results_url?:string|null;
}

interface Timing {
  id?:number;
  client_uuid?:string;
  elapsed_ms:number;
  recorded_at:string;
  entry?:{id:number;bib_number:string|null;display_name?:string};
  checkpoint?:{id:number;name:string};
}

const props=defineProps<{
  race:PublicRace;
  token:string;
  participants:StationParticipant[];
  recentTimings:Timing[];
  completedCount:number;
  serverNow:string;
}>();

const participants=ref(props.participants.map(item=>({...item,completed_checkpoint_ids:[...item.completed_checkpoint_ids]})));
const recent=ref(props.recentTimings.map(item=>({...item})));
const completedCount=ref(props.completedCount);
watch(() => props.participants, value => { participants.value=value.map(item=>({...item,completed_checkpoint_ids:[...item.completed_checkpoint_ids]})); });
watch(() => props.recentTimings, value => { recent.value=value.map(item=>({...item})); });
watch(() => props.completedCount, value => { completedCount.value=value; });
useRaceRefresh(() => ['race','participants','recentTimings','completedCount','serverNow']);
const checkpointId=ref<number|null>(props.race.checkpoints[0]?.id??null);
const selectedCheckpoint=computed(()=>props.race.checkpoints.find(cp=>cp.id===Number(checkpointId.value))??null);
const search=ref('');
const saving=ref(new Set<number>());
const feedback=ref<{type:'ok'|'error';message:string}|null>(null);
const confirmation=ref<{participant:StationParticipant;clientUuid:string;message:string}|null>(null);

const filtered=computed(()=>{
  const term=search.value.trim().toLowerCase();
  if(!term)return participants.value;
  return participants.value.filter(participant=>
    (participant.bib_number??'').toLowerCase().includes(term)
    || participant.name.toLowerCase().includes(term)
    || participant.members.some(member=>member.name.toLowerCase().includes(term))
  );
});

const recorded=(participant:StationParticipant)=>!!selectedCheckpoint.value
  && participant.completed_checkpoint_ids.includes(selectedCheckpoint.value.id);

const disciplineLabel=(value:string)=>value.charAt(0).toUpperCase()+value.slice(1);

function showFeedback(type:'ok'|'error',message:string){
  feedback.value={type,message};
  window.setTimeout(()=>{if(feedback.value?.message===message)feedback.value=null;},4000);
}

async function record(participant:StationParticipant,override=false,clientUuid=uuid()){
  if(!selectedCheckpoint.value||saving.value.has(participant.id))return;
  if(!props.race.started_at){showFeedback('error','The race has not started yet.');return;}
  if(props.race.finished_at){showFeedback('error','The race is finished.');return;}
  if(participant.status&&participant.status!=='registered'){showFeedback('error',`${participant.name} is marked ${participant.status.toUpperCase()}.`);return;}
  if(recorded(participant)){showFeedback('error',`${participant.name} already has a time at this checkpoint.`);return;}

  const selected=selectedCheckpoint.value;
  saving.value.add(participant.id);
  try{
    const {response,data}=await jsonRequest<{
      timing?:Timing;
      message?:string;
      warning?:boolean;
      missing_checkpoints?:string[];
      auto_finished?:boolean;
    }>(`/race/${props.token}/timings`,{
      method:'POST',
      body:JSON.stringify({
        entry_id:participant.id,
        checkpoint_id:selected.id,
        client_uuid:clientUuid,
        observed_at:new Date().toISOString(),
        override_warning:override,
      }),
    });

    if(response.ok&&data.timing){
      participant.completed_checkpoint_ids.push(selected.id);
      recent.value.unshift(data.timing);
      recent.value=recent.value.slice(0,12);
      if(selected.kind==='finish')completedCount.value+=1;
      search.value='';
      showFeedback('ok',data.message??'Time recorded.');
      if(data.auto_finished)router.reload({only:['race','completedCount','serverNow']});
      return;
    }

    if(response.status===409&&data.warning){
      const missing=(data.missing_checkpoints??[]).join(', ');
      confirmation.value={
        participant,
        clientUuid,
        message:`${data.message??'An earlier checkpoint is missing.'}${missing?` Missing: ${missing}.`:''} Record anyway?`,
      };
      return;
    }

    showFeedback('error',data.message??'The time was not recorded. Try again.');
  }catch{
    showFeedback('error','The time was not recorded. Check the connection and try again.');
  }finally{
    saving.value.delete(participant.id);
  }
}

function confirmOverride(){
  const pending=confirmation.value;
  confirmation.value=null;
  if(pending)void record(pending.participant,true,pending.clientUuid);
}
</script>

<template>
  <Head :title="`${race.name} · Race timing`"/>
  <AppLayout :title="race.name" public-view>
    <section class="panel-pad mb-5">
      <div class="flex flex-col gap-5 lg:flex-row lg:items-center lg:justify-between">
        <div>
          <div class="text-xs font-bold uppercase tracking-[.18em] text-accent">Race-day timing</div>
          <h2 class="mt-1 text-xl font-bold">Choose the checkpoint, then tap the athlete</h2>
          <p class="mt-2 muted">No account is needed. Every tap records the current race time at the selected checkpoint.</p>
        </div>
        <div class="rounded-2xl bg-canvas p-4">
          <div class="mb-1 text-xs font-bold uppercase tracking-[.18em] text-accent">Race clock</div>
          <RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow"/>
        </div>
      </div>
    </section>

    <section class="panel overflow-hidden">
      <div class="border-b border-outline p-4">
        <div class="grid gap-4 md:grid-cols-[minmax(0,.8fr)_minmax(0,1.2fr)]">
          <div>
            <label for="public-timing-checkpoint" class="label">1. Checkpoint</label>
            <select id="public-timing-checkpoint" v-model="checkpointId" class="field min-h-14 text-lg">
              <option v-for="checkpoint in race.checkpoints" :key="checkpoint.id" :value="checkpoint.id">{{ checkpoint.name }}</option>
            </select>
          </div>
          <div>
            <label for="public-timing-search" class="label">2. Find athlete</label>
            <input id="public-timing-search" v-model="search" class="field min-h-14 text-lg" placeholder="Name or bib number" autocomplete="off">
          </div>
        </div>

        <p v-if="selectedCheckpoint" class="mt-3 rounded-xl bg-canvas p-3 text-sm">
          Recording at <strong>{{ selectedCheckpoint.name }}</strong>. Tap the correct athlete once.
        </p>
        <p v-if="feedback" class="mt-3 rounded-xl p-3 text-sm font-semibold" :class="feedback.type==='ok'?'bg-emerald-500/10 text-success':'bg-red-500/10 text-error'" role="status">{{ feedback.message }}</p>
      </div>

      <div v-if="race.started_at && !race.finished_at && selectedCheckpoint" class="max-h-[62dvh] overflow-y-auto p-2 sm:p-3">
        <button
          v-for="participant in filtered"
          :key="participant.id"
          type="button"
          class="mb-2 grid min-h-20 w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 rounded-2xl border p-3 text-left transition sm:p-4"
          :class="recorded(participant)?'border-emerald-500/30 bg-emerald-500/10 opacity-70':'border-outline bg-surface hover:border-cyan-400 hover:bg-raised active:scale-[.995]'"
          :disabled="recorded(participant)||saving.has(participant.id)||(!!participant.status&&participant.status!=='registered')"
          @click="record(participant)"
        >
          <span class="max-w-24 rounded-xl bg-canvas px-3 py-2 font-mono text-lg font-black sm:text-xl">{{ bibLabel(participant.bib_number) }}</span>
          <span class="min-w-0">
            <strong class="block truncate text-base sm:text-lg">{{ participant.name }}</strong>
            <span class="block truncate text-xs muted sm:text-sm">{{ participant.type==='relay'?participant.members.map(member=>`${disciplineLabel(member.discipline)}: ${member.name}`).join(' · '):'Solo athlete' }}</span>
          </span>
          <span class="text-xs font-bold sm:text-sm" :class="recorded(participant)?'text-success':'text-accent'">{{ participant.status&&participant.status!=='registered'?participant.status.toUpperCase():saving.has(participant.id)?'SAVING':recorded(participant)?'RECORDED':'TAP' }}</span>
        </button>
        <p v-if="!filtered.length" class="p-8 text-center muted">No matching athlete.</p>
      </div>

      <div v-else class="p-8 text-center">
        <strong>{{ race.finished_at?'This race is finished.':'Timing opens when the organizer starts the race.' }}</strong>
        <p class="mt-2 muted">{{ race.finished_at?'Recorded results remain available from the organizer.':'You can leave this page open and start tapping athletes once the race begins.' }}</p>
      </div>
    </section>

    <section class="panel mt-5 overflow-hidden">
      <div class="border-b border-outline p-4">
        <div class="flex items-center justify-between gap-3">
          <h3 class="font-bold">Latest times</h3>
          <span class="badge">{{ completedCount }} finished</span>
        </div>
      </div>
      <div v-if="recent.length" class="divide-y divide-outline">
        <div v-for="timing in recent" :key="timing.id??timing.client_uuid" class="flex items-center justify-between gap-3 p-4">
          <div class="min-w-0">
            <strong class="block truncate">{{ timing.entry?.display_name??bibLabel(timing.entry?.bib_number) }}</strong>
            <span class="text-sm muted">{{ timing.checkpoint?.name }}</span>
          </div>
          <span class="shrink-0 font-mono font-bold">{{ formatDuration(timing.elapsed_ms,2) }}</span>
        </div>
      </div>
      <div v-else class="p-6 text-center muted">No times yet.</div>
      <div v-if="race.results_url" class="border-t border-outline p-4">
        <a :href="race.results_url" class="font-semibold text-accent underline">Open live results</a>
      </div>
    </section>

    <ConfirmDialog
      v-if="confirmation"
      title="Record this time?"
      :message="confirmation.message"
      confirm-label="Record anyway"
      @cancel="confirmation=null"
      @confirm="confirmOverride"
    />
  </AppLayout>
</template>
