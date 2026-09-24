<script setup lang="ts">
import { usePermissions } from '../../Composables/usePermissions';
const can=usePermissions();
import ResultSplits from '../../Components/ResultSplits.vue';
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { Head, usePage, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDuration, bibLabel } from '../../lib';
import { formatDate } from '../../presentation';
import type { Checkpoint, PageProps, Race } from '../../types';
interface Row { id:number;place:number|null;result_status:string;bib_number:string|null;type:string;name:string;category?:string;members:Array<{discipline:string;name:string}>;splits:Array<{checkpoint:string;elapsed_ms:number|null;split_ms:number|null}>;total_ms:number|null;gap_ms:number|null;finished:boolean }
const props=defineProps<{race:Race & {checkpoints:Checkpoint[]};results:Row[];categories:string[];filters:{type?:string;category?:string;status?:string};publicMode?:boolean}>();
const page=usePage<PageProps>();
const precision=ref<2|3>(2);
const expanded=ref<number[]>([]);
const display=ref(false), rotate=ref(true), pageNumber=ref(0);
const filters=ref({type:props.filters.type??'',category:props.filters.category??'',status:props.filters.status??''});
watch(filters,()=>router.get(page.url.split('?')[0],filters.value,{preserveState:true,preserveScroll:true,replace:true}),{deep:true});
const exportQuery=computed(()=>new URLSearchParams(filters.value).toString());
const canExport=computed(()=>!props.publicMode&&can('results.export'));
const checkpoints=computed(()=>props.race.checkpoints.filter(cp=>cp.kind!=='start'));
const pages=computed(()=>Math.max(1,Math.ceil(props.results.length/10)));
const visibleRows=computed(()=>display.value?props.results.slice((pageNumber.value%pages.value)*10,(pageNumber.value%pages.value)*10+10):props.results);
const toggleRow=(id:number)=>expanded.value=expanded.value.includes(id)?expanded.value.filter(value=>value!==id):[...expanded.value,id];
const toggleDisplay=()=>{display.value=!display.value;pageNumber.value=0;};
const screenError=ref('');
const fullscreen=async()=>{try { if(document.fullscreenElement)await document.exitFullscreen();else await document.documentElement.requestFullscreen(); }catch{screenError.value='Fullscreen is not available in this browser. Display mode still works.';}};
let rotation:number|undefined;
onMounted(()=>{rotation=window.setInterval(()=>{if(display.value&&rotate.value)pageNumber.value=(pageNumber.value+1)%pages.value;},15000);});
onBeforeUnmount(()=>window.clearInterval(rotation));
useRaceRefresh(()=>['race','results','categories']);
</script>
<template>
<Head :title="`${race.name} results`"/><AppLayout :title="`${race.name} · Results`" :public-view="publicMode" :display-mode="display">
  <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between"><div><span class="badge" :data-status="race.status">{{ race.finished_at?'Race closed':race.started_at?'Live results':'Awaiting start' }}</span><p class="mt-2 text-sm muted">{{ formatDate(race.event_date) }} · Updates automatically. {{ race.finished_at?'Corrections may still change results.':'' }}</p></div><div v-if="display" class="flex flex-wrap gap-2"><button class="btn-primary" @click="toggleDisplay"><i class="fa-solid fa-compress" aria-hidden="true"></i>Exit display mode</button><button class="btn-icon" aria-label="Toggle fullscreen" title="Toggle fullscreen" @click="fullscreen"><i class="fa-solid fa-expand" aria-hidden="true"></i></button></div></div>
  <p v-if="screenError" role="status" class="mb-4 text-warning">{{ screenError }}</p>
  <section v-show="!display" class="panel-pad mb-4 grid gap-3 sm:grid-cols-3" aria-label="Result filters"><label class="label">Participant type<select v-model="filters.type" class="field"><option value="">All types</option><option value="solo">Solo</option><option value="relay">Relay</option></select></label><label class="label">Category<select v-model="filters.category" class="field"><option value="">All categories</option><option v-for="category in categories" :key="category">{{ category }}</option></select></label><label class="label">Result status<select v-model="filters.status" class="field"><option value="">All statuses</option><option>FINISHED</option><option>IN PROGRESS</option><option>DNS</option><option>DNF</option><option>DSQ</option></select></label><p class="muted text-xs sm:col-span-3">Places and gaps apply to the selected group. Equal total milliseconds share a place (1, 1, 3). DNS, DNF and DSQ keep their timings but receive no place.</p></section>
  <div v-if="!display" class="mb-4 flex flex-wrap gap-2">
    <details class="rounded-xl border border-outline bg-surface p-2">
      <summary class="btn-secondary list-none"><i class="fa-solid fa-display" aria-hidden="true"></i>Display options</summary>
      <div class="mt-3 w-full max-w-sm space-y-3 p-2">
        <label class="label">Timing precision<select v-model="precision" class="field mt-1"><option :value="2">Hundredths (0.01 s)</option><option :value="3">Milliseconds (0.001 s)</option></select></label>
        <p class="text-xs muted">Places always use full millisecond precision.</p>
        <button class="btn-primary w-full" @click="toggleDisplay"><i class="fa-solid fa-display" aria-hidden="true"></i>Large-screen display</button>
      </div>
    </details>
    <details v-if="canExport" class="rounded-xl border border-outline bg-surface p-2">
      <summary class="btn-secondary list-none"><i class="fa-solid fa-download" aria-hidden="true"></i>Export</summary>
      <div class="mt-3 grid gap-2 p-2"><a :href="`/races/${race.id}/results.csv?${exportQuery}`" class="btn-secondary"><i class="fa-solid fa-file-csv" aria-hidden="true"></i>CSV</a><a :href="`/races/${race.id}/results.xlsx?${exportQuery}`" class="btn-secondary"><i class="fa-solid fa-file-excel" aria-hidden="true"></i>Excel</a></div>
    </details>
  </div>
  <div v-else class="mb-4 flex flex-wrap items-center gap-3"><label class="flex items-center gap-2"><input v-model="rotate" type="checkbox">Rotate pages every 15 seconds</label><span class="text-sm muted">Timing precision: {{ precision===3?'milliseconds':'hundredths' }}</span></div>
  <p v-if="display" class="mb-3 text-lg font-semibold">{{ filters.category||'All categories' }} · {{ filters.type||'All participant types' }} · {{ filters.status||'All statuses' }}</p>
  <section v-if="!display" class="space-y-3 sm:hidden" aria-label="Race results"><article v-for="row in visibleRows" :key="row.id" class="panel-pad"><div class="flex items-start gap-3"><div class="shrink-0 text-center"><span class="block text-xs muted">Place</span><strong class="text-2xl">{{ row.place??'—' }}</strong></div><div class="min-w-0"><h2 class="font-bold">{{ row.name }}</h2><p class="mt-1 text-sm muted">{{ bibLabel(row.bib_number) }} · {{ row.category }} · {{ row.result_status }}</p></div></div><dl class="mt-4 grid grid-cols-1 gap-3 min-[360px]:grid-cols-2"><div><dt class="text-xs muted">Total time</dt><dd class="font-mono text-lg font-bold tabular-nums" :class="row.finished?'text-success':'text-muted'">{{ formatDuration(row.total_ms,precision) }}</dd></div><div><dt class="text-xs muted">Gap to leader</dt><dd class="font-mono tabular-nums">{{ row.gap_ms===0?'Leader':row.gap_ms==null?'—':'+'+formatDuration(row.gap_ms,precision) }}</dd></div></dl><button class="btn-secondary mt-4 w-full" :aria-expanded="expanded.includes(row.id)" :aria-controls="`mobile-splits-${row.id}`" @click="toggleRow(row.id)"><i :class="expanded.includes(row.id)?'fa-solid fa-eye-slash':'fa-solid fa-eye'" aria-hidden="true"></i>{{ expanded.includes(row.id)?'Hide splits':'View splits' }}</button><div v-if="expanded.includes(row.id)" :id="`mobile-splits-${row.id}`" class="mt-3"><ResultSplits :race="race" :checkpoints="checkpoints" :splits="row.splits" :precision="precision"/><p v-if="row.type==='relay'" class="mt-3 text-sm muted">{{ row.members.map(member=>`${member.discipline}: ${member.name}`).join(' · ') }}</p></div></article><p v-if="!results.length" class="panel-pad muted">No participants match these results yet.</p></section>
  <p v-if="display" class="mb-2 text-sm muted sm:hidden">Swipe the results table sideways to see all columns.</p>
  <div class="scroll-region panel overflow-x-auto" :class="display?'':'hidden sm:block'" tabindex="0" role="region" aria-label="Results table"><table class="w-full min-w-[620px] text-left" :class="display?'text-xl':'text-sm'"><caption class="sr-only">Race results ordered by finish time, with expandable checkpoint timings</caption><thead class="bg-surface text-xs uppercase tracking-wide text-muted"><tr><th class="sticky left-0 z-20 w-20 min-w-20 bg-surface p-3 whitespace-nowrap">Place</th><th class="sm:sticky left-20 z-20 min-w-40 bg-surface p-3">Participant</th><th class="p-3">Total time</th><th class="p-3">Gap to leader</th><th v-if="!display" class="p-3">Checkpoints</th></tr></thead><tbody>
  <template v-for="row in visibleRows" :key="row.id"><tr class="border-t border-outline"><td class="sticky left-0 z-10 bg-surface p-3 font-bold">{{ row.place??'—' }}</td><td class="sm:sticky left-20 z-10 max-w-56 bg-surface p-3"><div class="font-semibold break-words">{{ row.name }}</div><div class="mt-1 text-xs muted">{{ bibLabel(row.bib_number) }} · {{ row.category }} · {{ row.result_status }}</div></td><td class="whitespace-nowrap p-3 font-mono font-bold tabular-nums" :class="row.finished?'text-success':'text-muted'">{{ formatDuration(row.total_ms,precision) }}</td><td class="whitespace-nowrap p-3 font-mono tabular-nums">{{ row.gap_ms===0?'Leader':row.gap_ms==null?'—':'+'+formatDuration(row.gap_ms,precision) }}</td><td v-if="!display" class="p-3"><button class="btn-secondary whitespace-nowrap" :aria-expanded="expanded.includes(row.id)" :aria-controls="`splits-${row.id}`" @click="toggleRow(row.id)"><i :class="expanded.includes(row.id)?'fa-solid fa-eye-slash':'fa-solid fa-eye'" aria-hidden="true"></i>{{ expanded.includes(row.id)?'Hide splits':'View splits' }}</button></td></tr>
  <tr v-if="!display&&expanded.includes(row.id)"><td colspan="5" class="bg-canvas p-4"><ResultSplits :id="`splits-${row.id}`" :race="race" :checkpoints="checkpoints" :splits="row.splits" :precision="precision"/><p v-if="row.type==='relay'" class="mt-3 text-sm muted">{{ row.members.map(member=>`${member.discipline}: ${member.name}`).join(' · ') }}</p></td></tr></template>
  <tr v-if="!results.length"><td :colspan="display?4:5" class="p-8 text-center muted">{{ filters.type||filters.category||filters.status?'No participants match these filters.':'No participants yet. Results will appear here when participants are added.' }}</td></tr>
  </tbody></table></div>
  <div v-if="display" class="mt-4 flex flex-wrap items-center justify-center gap-3"><button class="btn-secondary" @click="pageNumber=(pageNumber+pages-1)%pages"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i>Previous page</button><span>Page {{ pageNumber%pages+1 }} of {{ pages }}</span><button class="btn-secondary" @click="pageNumber=(pageNumber+1)%pages">Next page<i class="fa-solid fa-chevron-right" aria-hidden="true"></i></button></div>
</AppLayout></template>
