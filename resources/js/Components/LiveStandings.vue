<script setup lang="ts">
import { computed, ref } from 'vue';
import { bibLabel, formatDuration } from '../lib';
import type { Checkpoint, LiveStanding } from '../types';

const props = defineProps<{
  standings: LiveStanding[];
  checkpoints: Checkpoint[];
  started: boolean;
  finished: boolean;
}>();
const search = ref('');
const checkpoints = computed(() => props.checkpoints
  .filter(checkpoint => checkpoint.is_active && checkpoint.kind !== 'start')
  .slice().sort((a, b) => a.sequence - b.sequence));
const rows = computed(() => {
  const term = search.value.trim().toLocaleLowerCase();
  return props.standings.filter(row => !term || `${row.name} ${row.bib_number ?? ''}`.toLocaleLowerCase().includes(term));
});
</script>

<template>
  <section class="panel min-w-0 overflow-hidden" :aria-label="$t('Live standings')">
    <div class="space-y-3 border-b border-outline p-4">
      <div class="flex flex-wrap items-center justify-between gap-2">
        <h2 class="text-xl font-bold">{{ $t('Live standings') }}</h2>
        <span class="badge">{{ finished ? $t('Race finished') : started ? $t('Live') : $t('Awaiting start') }}</span>
      </div>
      <p class="text-sm muted">{{ $t('Provisional positions: furthest checkpoint first, then fastest time there. Times are measured from the race start. Updates every 5 seconds while online.') }}</p>
      <label class="block max-w-sm">
        <span class="sr-only">{{ $t('Search standings') }}</span>
        <input v-model="search" type="search" class="field" :placeholder="$t('Bib number or name')" :aria-label="$t('Search standings')">
      </label>
    </div>
    <p class="px-4 pt-3 text-xs muted sm:hidden">{{ $t('Swipe sideways to see all checkpoint times.') }}</p>
    <div class="scroll-region max-h-[65dvh] overflow-auto" tabindex="0" role="region" :aria-label="$t('Live standings table')">
      <table class="w-full text-left text-sm">
        <caption class="sr-only">{{ $t('Current positions and cumulative checkpoint times. Equal times at the same checkpoint share a position. DNS, DNF and DSQ are not ranked.') }}</caption>
        <thead class="sticky top-0 z-20 bg-surface text-xs text-muted">
          <tr>
            <th scope="col" class="sticky left-0 z-30 min-w-44 bg-surface p-3 sm:min-w-60">{{ $t('Place') }} · {{ $t('Participant') }}</th>
            <th scope="col" class="min-w-40 p-3">{{ $t('Latest checkpoint') }}</th>
            <th v-for="checkpoint in checkpoints" :key="checkpoint.id" scope="col" class="min-w-36 p-3">{{ $t(checkpoint.name) }}</th>
          </tr>
        </thead>
        <tbody>
          <tr v-for="row in rows" :key="row.id" class="border-t border-outline">
            <th scope="row" class="sticky left-0 z-10 bg-surface p-3 align-top font-normal">
              <div class="flex items-start gap-2 sm:gap-3">
                <strong class="w-6 shrink-0 text-lg tabular-nums text-accent">{{ row.place ?? '—' }}</strong>
                <div class="w-28 min-w-0 sm:w-44">
                  <strong class="block break-words">{{ row.name }}</strong>
                  <span class="mt-1 block break-words text-xs muted">{{ bibLabel(row.bib_number) }} · {{ $t(row.type) }}</span>
                  <span class="mt-1 block text-xs" :class="row.finished ? 'text-success' : 'text-muted'">{{ $t(row.result_status) }}</span>
                </div>
              </div>
            </th>
            <td class="p-3 align-top">
              <span class="block">{{ row.latest_checkpoint ? $t(row.latest_checkpoint) : $t('No time recorded yet') }}</span>
              <strong class="mt-1 block whitespace-nowrap font-mono tabular-nums">{{ formatDuration(row.latest_elapsed_ms, 2) }}</strong>
            </td>
            <td v-for="checkpoint in checkpoints" :key="checkpoint.id" class="whitespace-nowrap p-3 align-top font-mono tabular-nums" :class="checkpoint.kind === 'finish' && row.finished ? 'font-bold text-success' : ''">
              {{ formatDuration(row.splits.find(split => split.checkpoint_id === checkpoint.id)?.elapsed_ms ?? null, 2) }}
            </td>
          </tr>
          <tr v-if="!rows.length"><td :colspan="checkpoints.length + 2" class="p-6 muted">{{ search ? $t('No matching athlete.') : $t('No participants yet. Standings will appear here when athletes are added.') }}</td></tr>
        </tbody>
      </table>
    </div>
  </section>
</template>
