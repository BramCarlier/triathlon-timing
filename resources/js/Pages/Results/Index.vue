<script setup lang="ts">
import { usePermissions } from '../../Composables/usePermissions';
const can=usePermissions();
import { checkpointDistanceText } from '../../checkpointDistance';
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
  <div class="mb-5 flex flex-wrap items-center justify-between gap-3"><div><span class="badge" :data-status="race.status">{{ race.finished_at?'Race closed':race.started_at?'Live results':'Awaiting start' }}</span><p class="mt-2 text-sm muted">{{ formatDate(race.event_date) }} · Updates automatically every 5 seconds. {{ race.finished_at?'Official corrections may still change results.':'' }}</p></div><div class="flex flex-wrap gap-2"><a v-if="canExport" :href="`/races/${race.id}/results.csv?${exportQuery}`" class="btn-secondary">Export CSV</a><a v-if="canExport" :href="`/races/${race.id}/results.xlsx?${exportQuery}`" class="btn-secondary">Export XLSX</a><button class="btn-primary" @click="toggleDisplay">{{ display?'Exit display mode':'Large-screen display' }}</button><button v-if="display" class="btn-secondary" @click="fullscreen">Toggle fullscreen</button></div></div>
  <p v-if="screenError" role="status" class="mb-4 text-warning">{{ screenError }}</p>
  <section v-show="!display" class="panel-pad mb-4 grid gap-3 sm:grid-cols-3" aria-label="Result filters"><label class="label">Entry type<select v-model="filters.type" class="field"><option value="">All types</option><option value="solo">Solo</option><option value="relay">Relay</option></select></label><label class="label">Category<select v-model="filters.category" class="field"><option value="">All categories</option><option v-for="category in categories" :key="category">{{ category }}</option></select></label><label class="label">Result status<select v-model="filters.status" class="field"><option value="">All statuses</option><option>FINISHED</option><option>IN PROGRESS</option><option>DNS</option><option>DNF</option><option>DSQ</option></select></label><p class="muted text-xs sm:col-span-3">Places and gaps apply to the selected group. Equal total milliseconds share a place (1, 1, 3). DNS, DNF and DSQ keep their timings but receive no place.</p></section>
  <div class="mb-4 flex flex-wrap items-center gap-3"><label class="label">Timing precision<select v-model="precision" class="field mt-1"><option :value="2">Hundredths (0.01 s)</option><option :value="3">Milliseconds (0.001 s)</option></select></label><p class="muted text-sm">Places use full millisecond precision. Use milliseconds for finishes less than 0.01 seconds apart.</p><label v-if="display" class="ml-auto flex items-center gap-2"><input v-model="rotate" type="checkbox">Rotate pages every 15 seconds</label></div>
  <p v-if="display" class="mb-3 text-lg font-semibold">{{ filters.category||'All categories' }} · {{ filters.type||'All entry types' }} · {{ filters.status||'All statuses' }}</p>
  <div class="panel overflow-x-auto"><table class="w-full min-w-[620px] text-left" :class="display?'text-xl':'text-sm'"><caption class="sr-only">Race results ordered by finish time, with expandable checkpoint timings</caption><thead class="bg-surface text-xs uppercase tracking-wide text-muted"><tr><th class="sticky left-0 z-20 w-14 bg-surface p-3">Place</th><th class="sticky left-14 z-20 min-w-40 bg-surface p-3">Participant</th><th class="p-3">Total time</th><th class="p-3">Gap to leader</th><th v-if="!display" class="p-3">Checkpoints</th></tr></thead><tbody>
  <template v-for="row in visibleRows" :key="row.id"><tr class="border-t border-outline"><td class="sticky left-0 z-10 bg-surface p-3 font-bold">{{ row.place??'—' }}</td><td class="sticky left-14 z-10 max-w-56 bg-surface p-3"><div class="font-semibold break-words">{{ row.name }}</div><div class="mt-1 text-xs muted">{{ bibLabel(row.bib_number) }} · {{ row.category }} · {{ row.result_status }}</div></td><td class="whitespace-nowrap p-3 font-mono font-bold tabular-nums" :class="row.finished?'text-success':'text-muted'">{{ formatDuration(row.total_ms,precision) }}</td><td class="whitespace-nowrap p-3 font-mono tabular-nums">{{ row.gap_ms===0?'Leader':row.gap_ms==null?'—':'+'+formatDuration(row.gap_ms,precision) }}</td><td v-if="!display" class="p-3"><button class="btn-secondary whitespace-nowrap" :aria-expanded="expanded.includes(row.id)" :aria-controls="`splits-${row.id}`" @click="toggleRow(row.id)">{{ expanded.includes(row.id)?'Hide splits':'View splits' }}</button></td></tr>
  <tr v-if="!display&&expanded.includes(row.id)"><td colspan="5" class="bg-canvas p-4"><div :id="`splits-${row.id}`" class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><div v-for="(split,index) in row.splits" :key="index" class="rounded-xl border border-outline bg-surface p-3"><h3 class="font-bold" :data-discipline="checkpoints[index]?.discipline">{{ split.checkpoint }}</h3><p class="mt-1 text-xs muted">{{ checkpoints[index]?checkpointDistanceText(race,checkpoints[index]):'' }}</p><p class="mt-3 font-mono font-semibold">{{ formatDuration(split.elapsed_ms,precision) }}</p><p v-if="split.split_ms!==null" class="text-sm muted">split {{ formatDuration(split.split_ms,precision) }}</p></div></div><p v-if="row.type==='relay'" class="mt-3 text-sm muted">{{ row.members.map(member=>`${member.discipline}: ${member.name}`).join(' · ') }}</p></td></tr></template>
  <tr v-if="!results.length"><td :colspan="display?4:5" class="p-8 text-center muted">{{ filters.type||filters.category||filters.status?'No participants match these filters.':'No participants yet. Results will appear here when entries are added.' }}</td></tr>
  </tbody></table></div>
  <div v-if="display" class="mt-4 flex items-center justify-center gap-4"><button class="btn-secondary" @click="pageNumber=(pageNumber+pages-1)%pages">Previous page</button><span>Page {{ pageNumber%pages+1 }} of {{ pages }}</span><button class="btn-secondary" @click="pageNumber=(pageNumber+1)%pages">Next page</button></div>
</AppLayout></template>
