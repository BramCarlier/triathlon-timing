// Older API responses used UTC timestamps without their timezone marker.
export function parseRaceTimestamp(value: string): number {
  const timestamp = value.trim().replace(' ', 'T');
  return Date.parse(/[zZ]$|[+-]\d{2}:?\d{2}$/.test(timestamp) ? timestamp : `${timestamp}Z`);
}

export function raceElapsedMs(startedAt: string | null | undefined, finishedAt: string | null | undefined, nowMs: number): number {
  if (!startedAt) return 0;
  const end = finishedAt ? parseRaceTimestamp(finishedAt) : nowMs;
  const elapsed = end - parseRaceTimestamp(startedAt);
  return Number.isFinite(elapsed) ? Math.max(0, elapsed) : 0;
}
