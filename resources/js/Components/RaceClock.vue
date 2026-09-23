<script setup lang="ts">
import { computed } from 'vue';
import { useRaceClock } from '../Composables/useRaceClock';
import { formatDuration } from '../lib';
const props = defineProps<{ startedAt?: string|null; finishedAt?: string|null; serverNow: string; milliseconds?: boolean; compact?: boolean }>();
const { elapsedMs } = useRaceClock(() => props.startedAt, () => props.serverNow, () => props.finishedAt);
const text = computed(() => props.startedAt ? formatDuration(elapsedMs.value, props.milliseconds ?? true) : 'NOT STARTED');
</script>
<template>
  <div role="timer" aria-label="Shared race clock" class="font-mono font-bold tabular-nums tracking-tight" :class="compact ? 'text-2xl' : 'race-clock'">{{ text }}</div>
</template>
