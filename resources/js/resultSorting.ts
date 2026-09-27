export type ResultSortKey = 'place' | 'bib_number' | 'name' | 'total_ms' | 'gap_ms' | 'latest_checkpoint' | `checkpoint:${number}`;
export type SortDirection = 'asc' | 'desc';
export interface SortableResult {
  place: number | null;
  bib_number: string | null;
  name: string;
  total_ms?: number | null;
  gap_ms?: number | null;
  latest_elapsed_ms?: number | null;
  splits: Array<{ checkpoint_id:number; elapsed_ms:number|null }>;
}

/** Sort copies only; official places and full-precision times remain authoritative. */
export function sortResults<T extends SortableResult>(rows: readonly T[], key: ResultSortKey, direction: SortDirection, locale = 'en-GB'): T[] {
  const collator = new Intl.Collator(locale, { numeric:true, sensitivity:'base' });
  const sign = direction === 'asc' ? 1 : -1;
  const value = (row:T): string | number | null => {
    if (key.startsWith('checkpoint:')) return row.splits.find(split => split.checkpoint_id === Number(key.slice(11)))?.elapsed_ms ?? null;
    if (key === 'latest_checkpoint') {
      for (let index = row.splits.length - 1; index >= 0; index--) {
        if (row.splits[index].elapsed_ms !== null) return index;
      }
      return null;
    }
    return row[key as 'place' | 'bib_number' | 'name' | 'total_ms' | 'gap_ms'] ?? null;
  };
  return [...rows].sort((a, b) => {
    const left = value(a), right = value(b);
    const missingLeft = left === null || left === '', missingRight = right === null || right === '';
    // Missing times/bibs and unranked places stay at the bottom in either direction.
    if (missingLeft || missingRight) return Number(missingLeft) - Number(missingRight);
    const comparison = typeof left === 'number' && typeof right === 'number' ? left - right : collator.compare(String(left), String(right));
    if (comparison) return comparison * sign;
    if (key === 'latest_checkpoint') return ((a.latest_elapsed_ms ?? 0) - (b.latest_elapsed_ms ?? 0)) * sign;
    return 0;
  });
}
