<script setup lang="ts">
import { computed } from 'vue';
import { Link } from '@inertiajs/vue3';
import type { Race, Checkpoint } from '../types';
const props=defineProps<{race:Race & {checkpoints:Checkpoint[];organizers_count?:number;organizers?:Array<{id:number;is_active?:boolean}>}}>();
const checks=computed(()=>[
 {label:'Course distances',ready:['swim_km','bike_km','run_km'].every(key=>Number(props.race.settings?.[key])>0),href:`/races/${props.race.id}#course-distances`,detail:'Confirm the swim, bike and run distances.'},
 {label:'Checkpoints',ready:props.race.checkpoints.some(cp=>cp.is_active&&cp.kind==='finish')&&['swim','bike','run'].every(sport=>props.race.checkpoints.some(cp=>cp.is_active&&cp.discipline===sport)),href:`/races/${props.race.id}#checkpoints`,detail:'Review the order and ensure each leg and the finish are covered.'},
 {label:'Participants',ready:(props.race.entries_count??0)>0,href:`/races/${props.race.id}/participants`,detail:'Add or import the race entries.'},
 {label:'Officials',ready:(props.race.organizers_count??props.race.organizers?.filter(user=>user.is_active!==false).length??0)>0,href:`/races/${props.race.id}#organizers`,detail:'Assign active officials, then have them open their timing stations.'},
]);
</script>
<template><section v-if="!race.started_at" class="panel-pad mb-5"><div class="flex flex-wrap items-center justify-between gap-3"><h2 class="text-lg font-bold">Before race day</h2><span class="badge">{{ checks.filter(check=>check.ready).length }} of {{ checks.length }} checks ready</span></div><p class="mt-2 text-sm muted">Review these before starting the clock. A ready check confirms configuration; it does not replace checking the course and station devices.</p><div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-4"><Link v-for="check in checks" :key="check.label" :href="check.href" class="rounded-xl border border-outline p-3 hover:bg-raised"><div class="font-semibold"><span :class="check.ready?'text-success':'text-warning'">{{ check.ready?'✓ Ready':'! Review' }}</span> · {{ check.label }}</div><p class="mt-2 text-sm muted">{{ check.detail }}</p></Link></div></section></template>
