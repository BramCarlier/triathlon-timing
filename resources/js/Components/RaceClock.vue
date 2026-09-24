<script setup lang="ts">
import { tr } from '../i18n';
import { computed } from 'vue';
import { useRaceClock } from '../Composables/useRaceClock';
import { formatDuration } from '../lib';
const props = defineProps<{ startedAt?: string|null; finishedAt?: string|null; serverNow: string; milliseconds?: boolean; compact?: boolean }>();
const { elapsedMs } = useRaceClock(() => props.startedAt, () => props.serverNow, () => props.finishedAt);
const text = computed(() => props.startedAt ? formatDuration(elapsedMs.value, props.milliseconds ?? true) : tr('NOT STARTED'));
</script>
<template>
  <div role="timer" :aria-label="$t($t('Shared race clock'))" class="shrink-0 font-mono font-bold tabular-nums tracking-tight" :class="compact ? 'text-2xl' : 'race-clock'">{{ text }}</div>
</template>
