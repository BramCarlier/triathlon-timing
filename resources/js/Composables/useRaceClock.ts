import { computed, onBeforeUnmount, ref, toValue, watchEffect, type MaybeRefOrGetter } from 'vue';

export function useRaceClock(startedAt: MaybeRefOrGetter<string | null | undefined>, serverNow: MaybeRefOrGetter<string>) {
  let serverAnchor = Date.parse(toValue(serverNow));
  let performanceAnchor = performance.now();
  watchEffect(() => {
    const value = toValue(serverNow);
    serverAnchor = Date.parse(value);
    performanceAnchor = performance.now();
  });

  const tick = ref(0);
  const timer = window.setInterval(() => tick.value++, 50);
  onBeforeUnmount(() => window.clearInterval(timer));

  const serverNowMs = () => serverAnchor + (performance.now() - performanceAnchor);
  const elapsedMs = computed(() => {
    void tick.value;
    const start = toValue(startedAt);
    if (!start) return 0;
    return Math.max(0, serverNowMs() - Date.parse(start));
  });

  return { elapsedMs, serverNowMs };
}
