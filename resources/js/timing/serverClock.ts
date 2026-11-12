import { ref } from 'vue';
import { bestSample, sampleOffset, type ClockSample } from './clockSync';
import { parseRaceTimestamp } from '../raceTime';

/**
 * One shared estimate of the server clock for the whole page. Every tap is
 * stamped with serverNowMs(), never the device's own wall clock, so a phone
 * whose clock is a few seconds off still records correct times.
 */
const samples: ClockSample[] = [];
let pageOffsetMs: number | null = null;
let timer: number | undefined;
let users = 0;
export const clockQuality = ref<'page' | 'synced'>('page');
export const clockRttMs = ref<number | null>(null);

// Cancel in-flight samples when the page is left, so navigation does not report failed requests.
let leaving = new AbortController();
if (typeof window !== 'undefined') {
  // beforeunload fires before the browser cancels requests itself (WebKit reports those as errors).
  window.addEventListener('beforeunload', () => leaving.abort());
  window.addEventListener('pagehide', () => leaving.abort());
  window.addEventListener('pageshow', () => {
    if (leaving.signal.aborted) leaving = new AbortController();
  });
}

const localNow = () => performance.timeOrigin + performance.now();

/** Fallback before the first /time sample: the server time the page was rendered with. */
export function seedServerClock(serverNowIso: string | null | undefined): void {
  if (!serverNowIso || samples.length) return;
  const server = parseRaceTimestamp(serverNowIso);
  if (Number.isFinite(server)) pageOffsetMs = server - localNow();
}

export function serverNowMs(): number {
  const best = bestSample(samples, localNow());
  return localNow() + (best?.offsetMs ?? pageOffsetMs ?? 0);
}

export async function sampleServerClock(): Promise<void> {
  if (typeof navigator !== 'undefined' && !navigator.onLine) return;
  try {
    const sentAt = localNow();
    const response = await fetch('/time', {
      headers: { Accept: 'application/json' },
      cache: 'no-store',
      credentials: 'same-origin',
      signal: AbortSignal.any([AbortSignal.timeout(5000), leaving.signal]),
    });
    const receivedAt = localNow();
    if (!response.ok) return;
    const { now_ms: serverMs } = (await response.json()) as { now_ms: number };
    if (!Number.isFinite(serverMs)) return;
    samples.push(sampleOffset(sentAt, receivedAt, serverMs));
    if (samples.length > 12) samples.shift();
    const best = bestSample(samples, receivedAt);
    clockQuality.value = 'synced';
    clockRttMs.value = best ? Math.round(best.rttMs) : null;
  } catch {
    // Keep the previous estimate; the next sample retries.
  }
}

/** Start sampling (3 quick samples, then every 2 minutes) while any timing screen is open. */
export function startServerClock(serverNowIso?: string | null): () => void {
  seedServerClock(serverNowIso);
  users += 1;
  if (users === 1) {
    void (async () => {
      for (let i = 0; i < 3; i += 1) await sampleServerClock();
    })();
    timer = window.setInterval(() => void sampleServerClock(), 120_000);
    window.addEventListener('online', onWake);
    document.addEventListener('visibilitychange', onWake);
  }
  return () => {
    users = Math.max(0, users - 1);
    if (users === 0) {
      leaving.abort();
      leaving = new AbortController();
      window.clearInterval(timer);
      window.removeEventListener('online', onWake);
      document.removeEventListener('visibilitychange', onWake);
    }
  };
}

function onWake(): void {
  if (document.visibilityState === 'visible') void sampleServerClock();
}
