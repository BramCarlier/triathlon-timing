/**
 * Pure helpers for estimating the server clock from round-trip samples
 * (the midpoint method NTP uses). Kept free of browser APIs so they can be tested.
 */
export interface ClockSample {
  offsetMs: number;
  rttMs: number;
  takenAt: number;
}

/** Offset that maps a local timestamp onto the server timeline. */
export function sampleOffset(sentAt: number, receivedAt: number, serverMs: number): ClockSample {
  const rttMs = Math.max(0, receivedAt - sentAt);
  return { offsetMs: serverMs - (sentAt + rttMs / 2), rttMs, takenAt: receivedAt };
}

/**
 * Prefer the sample with the shortest round trip: its midpoint is the most
 * accurate. Samples older than maxAgeMs are ignored so a drifting device clock
 * is corrected over time.
 */
export function bestSample(samples: ClockSample[], now: number, maxAgeMs = 10 * 60_000): ClockSample | null {
  const fresh = samples.filter(sample => now - sample.takenAt <= maxAgeMs);
  const pool = fresh.length ? fresh : samples;
  return pool.reduce<ClockSample | null>((best, sample) => (!best || sample.rttMs < best.rttMs ? sample : best), null);
}
