<script setup lang="ts">
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import { useWakeLock } from '../../Composables/useWakeLock';
import RaceClock from '../../Components/RaceClock.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import LiveUpdatesStatus from '../../Components/LiveUpdatesStatus.vue';
import { useRaceClock } from '../../Composables/useRaceClock';
import { useOfflineTimingQueue } from '../../Composables/useOfflineTimingQueue';
import { formatDuration, jsonRequest, uuid, bibLabel } from '../../lib';
import type { Checkpoint, Race, StationParticipant, PageProps } from '../../types';

interface Timing { id?:number; client_uuid:string; elapsed_ms:number; recorded_at:string; entry?:{id:number;bib_number:string|null;display_name?:string;members?:unknown[]}; queued?:boolean }
const props = defineProps<{ race:Race; checkpoint?:Checkpoint|null; checkpoints:Checkpoint[]; recentTimings:Timing[]; participants:StationParticipant[]; serverNow:string }>();
const selectForm = useForm<{checkpoint_id:number|null}>({ checkpoint_id: props.checkpoint?.id ?? props.checkpoints[0]?.id ?? null });
const query = ref('');
watch(() => props.checkpoint?.id, id => { selectForm.checkpoint_id = id ?? null; });
const online = ref(navigator.onLine);
const feedback = ref<{type:'ok'|'error'|'offline';message:string}|null>(null);
const participants = ref(props.participants.map(p => ({...p, completed_checkpoint_ids:[...p.completed_checkpoint_ids]})));
const recent = ref<Timing[]>(props.recentTimings.map(t => ({...t, client_uuid: t.client_uuid ?? ''})));
watch(() => props.participants, value => { participants.value = value.map(p => ({...p, completed_checkpoint_ids:[...p.completed_checkpoint_ids]})); });
watch([() => props.checkpoint?.id, () => props.recentTimings], ([, value]) => {
  recent.value = value.map(t => ({...t, client_uuid:t.client_uuid ?? ''}));
});
const { elapsedMs, serverNowMs } = useRaceClock(() => props.race.started_at, () => props.serverNow, () => props.race.finished_at);
const account = usePage<PageProps>().props.auth.user!;
const { items:queuedItems, pending, foreignCount, legacy, error:storageError, flushing, queue, flush, refresh:refreshQueue, retry, discard } = useOfflineTimingQueue(props.race.id, account.id);
const saving=ref(new Set<number>());
const wake=useWakeLock();
const confirmation=ref<{message:string;label:string;action:()=>void|Promise<void>}|null>(null);
const confirmAction=async()=>{const action=confirmation.value?.action;confirmation.value=null;await action?.();};
const refreshRace = useRaceRefresh(() => ['race', 'serverNow', ...(pending.value===0 && saving.value.size===0 ? ['participants','recentTimings'] : [])]);
const retryPending=async(id:string,override=false)=>{try{await retry(id,override);refreshRace();}catch{showFeedback('error','Unable to access pending timings.');}};
const removePending=(id:string)=>{confirmation.value={message:'Discard this unsynchronized timing? Export a backup first if it may be needed.',label:'Discard timing',action:async()=>{await discard(id);refreshRace();}};};
const exportPending=()=>{const rows=[...queuedItems.value,...(account.role==='admin'?legacy.value:[])];const url=URL.createObjectURL(new Blob([JSON.stringify(rows,null,2)],{type:'application/json'}));const link=document.createElement('a');link.href=url;link.download=`race-${props.race.id}-pending-timings.json`;link.click();setTimeout(()=>URL.revokeObjectURL(url),1000);};
const deviceUuid = localStorage.getItem('triathlon-device-uuid') ?? uuid();
localStorage.setItem('triathlon-device-uuid', deviceUuid);

const filtered = computed(() => {
  const term = query.value.trim().toLowerCase();
  if (!term) return participants.value.slice(0, 40);
  return participants.value.filter(p => (p.bib_number ?? '').toLowerCase().includes(term) || p.name.toLowerCase().includes(term) || p.members.some(m => m.name.toLowerCase().includes(term))).slice(0, 40);
});
const priorRequiredIds = computed(() => props.checkpoint ? props.checkpoints.filter(cp => cp.is_required && cp.sequence < props.checkpoint!.sequence).map(cp => cp.id) : []);
const selected = (p:StationParticipant) => !!props.checkpoint && (p.completed_checkpoint_ids.includes(props.checkpoint.id) || queuedItems.value.some(i=>i.payload.entry_id===p.id && i.payload.checkpoint_id===props.checkpoint!.id));
const selectCheckpoint = () => selectForm.post(`/races/${props.race.id}/checkpoint-selection`);
const showFeedback = (type:'ok'|'error'|'offline', message:string) => { feedback.value = {type,message}; window.setTimeout(() => { if (feedback.value?.message === message) feedback.value = null; }, 3500); };

async function record(participant: StationParticipant, override = false, clientUuid = uuid()) {
  if (!props.checkpoint || saving.value.has(participant.id)) return;
  if (participant.status && participant.status!=='registered') {showFeedback('error','This entry is marked '+participant.status.toUpperCase()+'. Update its status in Participants before recording.');return;}
  if (!props.race.started_at) { showFeedback('error', 'The race clock has not started.'); return; }
  if (props.race.finished_at) { showFeedback('error', 'This race is finished. Use Race control for corrections.'); return; }
  if (selected(participant)) { showFeedback('error', `${participant.name} (${bibLabel(participant.bib_number)}) is already recorded here.`); return; }
  const missing = priorRequiredIds.value.filter(id => !participant.completed_checkpoint_ids.includes(id));
  if (missing.length && !override) {
    const names = props.checkpoints.filter(cp => missing.includes(cp.id)).map(cp => cp.name).join(', ');
    confirmation.value={message:`Earlier required timing missing: ${names}. Record ${participant.name} here anyway?`,label:'Record anyway',action:()=>record(participant,true,clientUuid)};return;
  }

  const payload = { operator_id:account.id, entry_id:participant.id, checkpoint_id:props.checkpoint.id, client_uuid:clientUuid, observed_at:new Date(serverNowMs()).toISOString(), source:online.value ? 'online' : 'offline', override_warning:override };
  const localTiming: Timing = { client_uuid:clientUuid, elapsed_ms:elapsedMs.value, recorded_at:String(payload.observed_at), entry:{id:participant.id,bib_number:participant.bib_number,display_name:participant.name}, queued:!online.value };

  saving.value.add(participant.id);
  const saveOffline=async()=>{
    await queue(`/races/${props.race.id}/timings`,payload,participant.name,localTiming.elapsed_ms);
    showFeedback('offline',`${participant.name} saved on this device. Pending sync.`);query.value='';
  };
  try {
    if(!online.value){await saveOffline();return;}
    let result;
    try {result=await jsonRequest<{timing?:Timing;message?:string;warning?:boolean;missing_checkpoints?:string[]}>(`/races/${props.race.id}/timings`,{method:'POST',body:JSON.stringify(payload),signal:AbortSignal.timeout(12000)});}
    catch {await saveOffline();return;}
    const {response,data}=result;
    if(response.ok&&data.timing){
      participant.completed_checkpoint_ids.push(props.checkpoint.id);
      recent.value.unshift({...data.timing,entry:{id:participant.id,bib_number:participant.bib_number,display_name:participant.name}});recent.value=recent.value.slice(0,10);
      showFeedback('ok',data.message??'Timing recorded.');query.value='';return;
    }
    if((response.ok&&!data.timing)||response.status>=500||[401,403,408,419,429].includes(response.status)){await saveOffline();return;}
    if(response.status===409&&data.warning){confirmation.value={message:`${data.message} ${(data.missing_checkpoints??[]).join(', ')}`,label:'Record anyway',action:()=>record(participant,true,clientUuid)};return;}
    showFeedback('error',data.message??'Timing could not be recorded.');
  } catch {showFeedback('error','Timing was NOT saved: device storage is unavailable. Use a backup stopwatch.');}
  finally {saving.value.delete(participant.id);}

}

async function undo(timing:Timing) {
  if (!props.checkpoint) return;
  const participant = participants.value.find(p => p.id === timing.entry?.id);
  if (timing.queued) {
    await discard(timing.client_uuid);
    if (participant) participant.completed_checkpoint_ids = participant.completed_checkpoint_ids.filter(id => id !== props.checkpoint!.id);
    recent.value = recent.value.filter(t => t.client_uuid !== timing.client_uuid);
    showFeedback('ok','Offline timing removed.'); return;
  }
  if (!timing.id || !online.value) { showFeedback('error','Reconnect before undoing a synced timing.'); return; }
  const {response} = await jsonRequest(`/races/${props.race.id}/timings/${timing.id}/void`, {method:'POST',body:JSON.stringify({reason:'Accidental checkpoint tap'})});
  if (response.ok) {
    if (participant) participant.completed_checkpoint_ids = participant.completed_checkpoint_ids.filter(id => id !== props.checkpoint!.id);
    recent.value = recent.value.filter(t => t.id !== timing.id); showFeedback('ok','Timing voided.');
  }
}

async function ping() {
  if (!props.checkpoint || !online.value) return;
  try {
    const { response, data } = await jsonRequest<{started_at?:string|null;finished_at?:string|null}>(`/races/${props.race.id}/presence`, {method:'POST',body:JSON.stringify({device_uuid:deviceUuid,checkpoint_id:props.checkpoint.id,pending_count:pending.value})});
    if (response.ok && ((data.started_at ?? null) !== (props.race.started_at ?? null) || (data.finished_at ?? null) !== (props.race.finished_at ?? null))) refreshRace();
  } catch { /* presence is best-effort */ }
}
const syncPending=async()=>{try{if(await flush())refreshRace();}catch{showFeedback('error','Unable to access pending timings. Keep this page open.');}};
const handleOnline = async () => { online.value=true; await syncPending(); await ping(); };
const handleFocus=()=>{if(document.visibilityState==='visible')void syncPending();};
const handleOffline = () => { online.value=false; showFeedback('offline','Offline mode. Timings will be kept on this device.'); };
let pingTimer:number|undefined;let syncTimer:number|undefined;
const channelName = `race.${props.race.id}`;
onMounted(async () => {
  syncTimer=window.setInterval(syncPending,15000);
  document.addEventListener('visibilitychange',handleFocus);
  window.addEventListener('online',handleOnline); window.addEventListener('offline',handleOffline);
  const echo = (window as any).Echo;
  if (echo) {
    echo.private(channelName)
      .listen('.race.started', () => router.reload({only:['race','serverNow']}))
      .listen('.race.finished', () => router.reload({only:['race','serverNow']}))
      .listen('.timing.recorded', (event:any) => { const p=participants.value.find(item=>item.id===event.entry_id); if(p && !p.completed_checkpoint_ids.includes(event.checkpoint_id)) p.completed_checkpoint_ids.push(event.checkpoint_id); })
      .listen('.timing.voided', (event:any) => { const p=participants.value.find(item=>item.id===event.entry_id); if(p) p.completed_checkpoint_ids=p.completed_checkpoint_ids.filter(id=>id!==event.checkpoint_id); });
  }
  pingTimer=window.setInterval(ping,30000);
  try { await refreshQueue(); await syncPending(); } catch { showFeedback('error', 'Unable to access saved offline timings. Keep this browser open and check device storage.'); }
  await ping();
});
onBeforeUnmount(() => {
  if(syncTimer)clearInterval(syncTimer);document.removeEventListener('visibilitychange',handleFocus);
  window.removeEventListener('online',handleOnline); window.removeEventListener('offline',handleOffline); if(pingTimer) clearInterval(pingTimer);
  const echo = (window as any).Echo; if(echo) echo.leave(channelName);
});
</script>
<template>
<Head :title="`${race.name} timing station`"/><AppLayout :title="`${race.name} · Timing station`">
  <div v-if="feedback" class="fixed inset-x-3 top-20 z-50 mx-auto max-w-xl rounded-2xl border p-4 text-center text-lg font-bold shadow-2xl" :class="feedback.type==='ok'?'border-emerald-400 bg-emerald-500 text-white':feedback.type==='offline'?'border-amber-300 bg-amber-500 text-slate-950':'border-red-400 bg-red-500 text-white'">{{ feedback.message }}</div>

  <section v-if="!checkpoint" class="mx-auto max-w-2xl panel-pad"><h2 class="text-xl font-bold">Choose this organizer's checkpoint</h2><p class="mt-2 muted">This selection belongs to this logged-in browser session. Every participant tap will be recorded at this checkpoint until you change it.</p><form class="mt-5" @submit.prevent="selectCheckpoint"><label class="label">Checkpoint</label><select v-model="selectForm.checkpoint_id" class="field"><option v-for="cp in checkpoints" :key="cp.id" :value="cp.id">{{ cp.sequence }} · {{ cp.name }}{{ cp.distance_km ? ` · ${cp.distance_km} km` : '' }}</option></select><button class="btn-primary mt-4 w-full">Start checkpoint mode</button></form></section>

  <template v-else>
    <section class="panel-pad mb-4" aria-label="Device timing recovery">
      <p v-if="storageError" role="alert" class="text-red-300">{{ storageError }}</p>
      <p v-if="foreignCount" class="text-amber-200">{{ foreignCount }} pending timings belong to another account. Sign in with that account to synchronize them.</p>
      <p v-if="legacy.length" class="text-amber-200">{{ legacy.length }} older timings have no recorded account owner. They will not upload automatically. An administrator can export them and review them in Race control.</p>
      <div class="flex flex-wrap items-center gap-3">
        <button class="btn-secondary" :disabled="flushing || !online" @click="syncPending">{{ flushing ? 'Syncing…' : 'Sync pending timings' }}</button>
        <button v-if="pending || (account.role==='admin' && legacy.length)" class="btn-secondary" @click="exportPending">Export pending backup</button>
        <button v-if="wake.supported" class="btn-secondary" @click="wake.toggle">{{ wake.enabled.value ? 'Allow screen sleep' : 'Keep screen awake' }}</button>
        <span v-if="wake.active.value" class="text-emerald-300">Screen stays awake</span><span v-if="wake.error.value" class="text-amber-200">{{ wake.error.value }}</span>
      </div>
      <div v-for="item in queuedItems" :key="item.client_uuid" class="mt-3 rounded-xl border border-amber-600/40 p-3">
        <strong>{{ item.label ?? 'Pending participant' }}</strong> · {{ formatDuration(item.elapsed_ms,true) }} · {{ checkpoints.find(c=>c.id===item.payload.checkpoint_id)?.name }}
        <p class="text-sm text-amber-200">{{ item.error ?? 'Saved on this device. Waiting to synchronize.' }}</p>
        <div class="mt-2 flex flex-wrap gap-2"><button class="btn-secondary" :disabled="flushing||!online" @click="retryPending(item.client_uuid)">Retry</button>
        <button v-if="item.warning" class="btn-secondary" :disabled="flushing||!online" @click="confirmation={message:'Record despite missing earlier checkpoints? This override is kept in the timing audit.',label:'Record anyway',action:()=>retryPending(item.client_uuid,true)}">Review checkpoint warning</button>
        <button class="btn-danger" :disabled="flushing" @click="removePending(item.client_uuid)">Discard</button></div>
      </div>
    </section>
    <p v-if="race.finished_at" class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-4 text-emerald-200">Race finished. The shared clock is stopped. Open Race control to correct timings.</p>
    <div class="mb-4 grid gap-3 lg:grid-cols-[1fr_auto]">
      <section class="panel-pad"><div class="flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between"><div><div class="text-xs font-semibold uppercase tracking-[.2em] text-cyan-300">{{ checkpoint.name }}</div><div class="mt-1 text-sm muted">{{ checkpoint.discipline ?? 'race' }}<span v-if="checkpoint.distance_km"> · {{ checkpoint.distance_km }} km</span></div></div><RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow"/></div></section>
      <section class="panel-pad flex min-w-64 flex-col justify-center"><div class="flex items-center justify-between"><span class="text-sm muted">Connection</span><span class="font-semibold" :class="online?'text-emerald-300':'text-amber-300'">{{ online ? 'Online' : 'Offline' }}</span></div><div class="mt-2 flex items-center justify-between"><span class="text-sm muted">Pending sync</span><span class="font-semibold" :class="pending?'text-amber-300':'text-slate-300'">{{ pending }}</span></div><div class="mt-2"><LiveUpdatesStatus :race-id="race.id"/></div><form class="mt-3" @submit.prevent="selectCheckpoint"><select v-model="selectForm.checkpoint_id" class="field text-sm" @change="selectCheckpoint"><option v-for="cp in checkpoints" :key="cp.id" :value="cp.id">Change to: {{ cp.name }}</option></select></form></section>
    </div>

    <div class="grid gap-4 xl:grid-cols-[1fr_360px]">
      <section class="panel overflow-hidden"><div class="border-b border-slate-800 p-4"><label class="label">Bib number or athlete/team name</label><input v-model="query" autofocus class="field min-h-14 text-xl" placeholder="Search name, team or bib" aria-label="Find participant"></div><div class="max-h-[62vh] overflow-auto p-2"><button v-for="participant in filtered" :key="participant.id" class="mb-2 grid min-h-20 w-full grid-cols-[auto_1fr_auto] items-center gap-4 rounded-2xl border p-4 text-left transition" :class="selected(participant)?'border-emerald-500/30 bg-emerald-500/10 opacity-70':'border-slate-800 bg-slate-900 hover:border-cyan-400 hover:bg-slate-800 active:scale-[.995]'" :disabled="saving.has(participant.id) || selected(participant) || !race.started_at || !!race.finished_at || (!!participant.status && participant.status!=='registered')" @click="record(participant)"><span class="rounded-xl bg-slate-950 px-3 py-2 font-mono text-xl font-black">{{ bibLabel(participant.bib_number) }}</span><span class="min-w-0"><span class="block truncate text-lg font-bold">{{ participant.name }}</span><span v-if="participant.type==='relay'" class="block truncate text-sm muted">{{ participant.members.map(m=>`${m.discipline}: ${m.name}`).join(' · ') }}</span><span v-else class="block text-sm muted">Solo athlete</span></span><span class="text-sm font-bold" :class="selected(participant)?'text-emerald-300':'text-cyan-300'">{{ participant.status && participant.status!=='registered' ? participant.status.toUpperCase() : saving.has(participant.id) ? 'SAVING' : selected(participant) ? 'RECORDED' : race.finished_at ? 'FINISHED' : !race.started_at ? 'WAITING' : 'TAP' }}</span></button><div v-if="!filtered.length" class="p-8 text-center muted">No matching participant.</div></div></section>
      <section class="panel overflow-hidden"><div class="flex items-center justify-between border-b border-slate-800 p-4"><h2 class="font-bold">Your recent taps</h2><span class="text-xs muted">Undo mistakes here</span></div><div v-if="recent.length" class="divide-y divide-slate-800"><div v-for="timing in recent" :key="timing.client_uuid || timing.id" class="p-4"><div class="flex items-center justify-between gap-3"><div><div class="font-mono text-lg font-bold">{{ timing.entry?.display_name ?? bibLabel(timing.entry?.bib_number) }}</div><div class="font-mono text-sm text-cyan-200">{{ formatDuration(timing.elapsed_ms,true) }}</div></div><div class="text-right"><span v-if="timing.queued" class="mb-1 block text-xs font-semibold text-amber-300">OFFLINE · PENDING</span><button class="rounded-lg px-3 py-2 text-sm font-semibold text-red-300 hover:bg-red-500/10" @click="undo(timing)">Undo</button></div></div></div></div><div v-else class="p-6 text-center muted">No timings recorded by you here yet.</div></section>
    </div>
    <div class="mt-4 text-center text-xs text-slate-500">Keep this page open before moving to a low-connectivity checkpoint. Failed submissions are queued locally and sync when the connection returns.</div>
  </template>
<ConfirmDialog v-if="confirmation" title="Confirm timing action" :message="confirmation.message" :confirm-label="confirmation.label" @cancel="confirmation=null" @confirm="confirmAction"/>
</AppLayout>
</template>
