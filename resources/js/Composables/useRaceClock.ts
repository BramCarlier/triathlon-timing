import { computed, onBeforeUnmount, ref, toValue, watch, type MaybeRefOrGetter } from 'vue';
import { raceElapsedMs } from '../raceTime';
import { seedServerClock, serverNowMs, startServerClock } from '../timing/serverClock';

/** Elapsed race time on the server's timeline, refreshed every 50 ms. */
export function useRaceClock(
  startedAt: MaybeRefOrGetter<string | null | undefined>,
  serverNow: MaybeRefOrGetter<string>,
  finishedAt: MaybeRefOrGetter<string | null | undefined> = null,
) {
  const stop = startServerClock(toValue(serverNow));
  watch(
    () => toValue(serverNow),
    value => seedServerClock(value),
  );

  const tick = ref(0);
  const timer = window.setInterval(() => {
    tick.value += 1;
  }, 50);
  onBeforeUnmount(() => {
    window.clearInterval(timer);
    stop();
  });

  const elapsedMs = computed(() => {
    void tick.value;
    return raceElapsedMs(toValue(startedAt), toValue(finishedAt), serverNowMs());
  });

  return { elapsedMs, serverNowMs };
}
