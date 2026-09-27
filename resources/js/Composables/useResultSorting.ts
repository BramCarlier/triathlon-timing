import { computed, ref } from 'vue';
import { localeTag } from '../i18n';
import { sortResults, type ResultSortKey, type SortDirection, type SortableResult } from '../resultSorting';

export function useResultSorting<T extends SortableResult>(rows: () => T[]) {
  const sortKey = ref<ResultSortKey>('place');
  const sortDirection = ref<SortDirection>('asc');
  const sortedRows = computed(() => sortResults(rows(), sortKey.value, sortDirection.value, localeTag()));
  const sortBy = (key:ResultSortKey) => {
    sortDirection.value = sortKey.value === key && sortDirection.value === 'asc' ? 'desc' : 'asc';
    sortKey.value = key;
  };
  return { sortKey, sortDirection, sortedRows, sortBy };
}
