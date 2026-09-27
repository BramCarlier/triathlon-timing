<script setup lang="ts">
import type { ResultSortKey, SortDirection } from '../resultSorting';
defineProps<{ column:ResultSortKey; label:string; sortKey:ResultSortKey; direction:SortDirection }>();
const emit = defineEmits<{ sort:[key:ResultSortKey] }>();
</script>

<template>
  <th scope="col" :aria-sort="sortKey===column ? (direction==='asc'?'ascending':'descending') : 'none'">
    <button type="button" class="flex min-h-11 w-full items-center gap-2 rounded-lg text-left font-semibold focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-accent" :title="sortKey===column && direction==='asc' ? $t('Sort descending') : $t('Sort ascending')" @click="emit('sort', column)">
      <span class="whitespace-nowrap">{{ label }}</span>
      <i class="shrink-0" :class="sortKey!==column?'fa-solid fa-sort text-muted':direction==='asc'?'fa-solid fa-sort-up text-accent':'fa-solid fa-sort-down text-accent'" aria-hidden="true"></i>
    </button>
  </th>
</template>
