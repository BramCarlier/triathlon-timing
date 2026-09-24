<script setup lang="ts">
import { checkpointDistanceText } from '../checkpointDistance';
import { formatDuration } from '../lib';
import type { Checkpoint, Race } from '../types';
defineProps<{race:Race;checkpoints:Checkpoint[];splits:Array<{checkpoint:string;elapsed_ms:number|null;split_ms:number|null}>;precision:2|3}>();
</script>
<template><div class="grid gap-3 sm:grid-cols-2 lg:grid-cols-3"><div v-for="(split,index) in splits" :key="index" class="rounded-xl border border-outline bg-surface p-3"><h3 class="font-bold" :data-discipline="checkpoints[index]?.discipline">{{ $t(split.checkpoint) }}</h3><p class="mt-1 text-xs muted">{{ checkpoints[index]?checkpointDistanceText(race,checkpoints[index]):'' }}</p><p class="mt-3 font-mono font-semibold">{{ formatDuration(split.elapsed_ms,precision) }}</p><p v-if="split.split_ms!==null" class="text-sm muted">{{ $t("split") }} {{ formatDuration(split.split_ms,precision) }}</p></div></div></template>
