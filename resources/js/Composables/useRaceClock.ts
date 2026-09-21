import { computed, onBeforeUnmount, ref, toValue, watchEffect, type MaybeRefOrGetter } from 'vue';
import { parseRaceTimestamp, raceElapsedMs } from '../raceTime';

export function useRaceClock(startedAt: MaybeRefOrGetter<string | null | undefined>, serverNow: MaybeRefOrGetter<string>, finishedAt: MaybeRefOrGetter<string | null | undefined> = null) {
  let serverAnchor = parseRaceTimestamp(toValue(serverNow));
  let performanceAnchor = performance.now();
  watchEffect(() => {
    const value = toValue(serverNow);
    serverAnchor = parseRaceTimestamp(value);
    performanceAnchor = performance.now();
  });

  const tick = ref(0);
  const timer = window.setInterval(() => tick.value++, 50);
  onBeforeUnmount(() => window.clearInterval(timer));

  const serverNowMs = () => serverAnchor + (performance.now() - performanceAnchor);
  const elapsedMs = computed(() => {
    void tick.value;
    return raceElapsedMs(toValue(startedAt), toValue(finishedAt), serverNowMs());
  });

  return { elapsedMs, serverNowMs };
}
