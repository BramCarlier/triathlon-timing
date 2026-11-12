<script setup lang="ts">
/** Mark: a stopwatch ring split into the swim, bike and run colours. */
defineProps<{ withName?: boolean }>();
</script>

<template>
  <span class="inline-flex items-center gap-2.5">
    <svg viewBox="0 0 40 40" class="size-9 shrink-0" aria-hidden="true" focusable="false">
      <rect x="17" y="1.5" width="6" height="4" rx="1.2" fill="currentColor" />
      <path d="M20 8.5a13.5 13.5 0 0 1 11.7 6.75" fill="none" stroke="var(--app-swim)" stroke-width="5" stroke-linecap="butt" />
      <path d="M31.7 15.25a13.5 13.5 0 0 1-11.7 20.25" fill="none" stroke="var(--app-bike)" stroke-width="5" />
      <path d="M20 35.5A13.5 13.5 0 0 1 20 8.5" fill="none" stroke="var(--app-run)" stroke-width="5" />
      <path d="M20 22V14" stroke="currentColor" stroke-width="3" stroke-linecap="round" />
      <circle cx="20" cy="22" r="2.6" fill="var(--app-buoy)" />
    </svg>
    <span v-if="withName" class="display text-xl font-bold leading-none">Triathlon Timing</span>
  </span>
</template>
