<script setup lang="ts">
import { formatDuration } from '../lib';
defineProps<{split?:{elapsed_ms:number|null;place?:number|null};precision:2|3}>();
</script>

<template>
  <div>
    <span class="whitespace-nowrap font-mono tabular-nums">{{ formatDuration(split?.elapsed_ms??null,precision) }}</span>
    <span v-if="split?.place!=null" class="mt-1 block whitespace-nowrap font-sans text-xs font-semibold text-accent">{{ $t('Place :place',{place:split.place}) }}</span>
  </div>
</template>
