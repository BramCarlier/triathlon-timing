/** Display precision never changes the stored time or ranking. */
export function formatDuration(ms: number | null | undefined, precision: boolean | 0 | 2 | 3 = false): string {
  if (ms === null || ms === undefined || !Number.isFinite(ms)) return '—';
  const total = Math.max(0, Math.floor(ms));
  const digits = precision === true ? 3 : precision === false ? 0 : precision;
  const base = [Math.floor(total / 3600000), Math.floor(total % 3600000 / 60000), Math.floor(total % 60000 / 1000)]
    .map(value => String(value).padStart(2, '0')).join(':');
  if (!digits) return base;
  return `${base}.${String(Math.floor((total % 1000) / (digits === 2 ? 10 : 1))).padStart(digits, '0')}`;
}
