<script setup lang="ts">
import { tr } from '../i18n';
import { computed } from 'vue';
import { useRaceClock } from '../Composables/useRaceClock';
import { formatDuration } from '../lib';

const props = withDefaults(
  defineProps<{
    startedAt?: string | null;
    finishedAt?: string | null;
    serverNow: string;
    milliseconds?: boolean;
    compact?: boolean;
    announce?: boolean;
  }>(),
  { milliseconds: false, announce: true },
);
const { elapsedMs } = useRaceClock(
  () => props.startedAt,
  () => props.serverNow,
  () => props.finishedAt,
);
const text = computed(() => (props.startedAt ? formatDuration(elapsedMs.value, props.milliseconds) : tr('NOT STARTED')));
</script>

<template>
  <div
    :role="announce === false ? undefined : 'timer'"
    :aria-label="announce === false ? undefined : $t('Shared race clock')"
    :aria-hidden="announce === false ? 'true' : undefined"
    class="race-clock shrink-0"
    :class="compact ? 'text-[1.65rem] sm:text-[2.3rem]' : ''"
  >
    {{ text }}
  </div>
</template>
