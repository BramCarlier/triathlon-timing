<script setup lang="ts">
import { computed } from 'vue';
import { useRaceClock } from '../Composables/useRaceClock';
import { formatDuration } from '../lib';
const props = defineProps<{ startedAt?: string|null; serverNow: string; milliseconds?: boolean; compact?: boolean }>();
const { elapsedMs } = useRaceClock(() => props.startedAt, () => props.serverNow);
const text = computed(() => props.startedAt ? formatDuration(elapsedMs.value, props.milliseconds ?? true) : 'NOT STARTED');
</script>
<template>
  <div class="font-mono font-bold tabular-nums tracking-tight" :class="compact ? 'text-2xl' : 'text-4xl sm:text-6xl'">{{ text }}</div>
</template>
