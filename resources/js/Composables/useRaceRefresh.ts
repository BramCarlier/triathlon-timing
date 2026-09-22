import { onBeforeUnmount, onMounted } from 'vue';
import { router } from '@inertiajs/vue3';

/** Reconcile after missed broadcasts, reconnects, or a suspended browser tab. */
export function useRaceRefresh(only: () => string[]) {
  let timer: number | undefined;
  let refreshing = false;
  let disposed = false;
  const refresh = () => {
    if (disposed || refreshing || !navigator.onLine) return;
    refreshing = true;
    router.reload({ only: only(), onFinish: () => { refreshing = false; } });
  };
  const visible = () => { if (document.visibilityState === 'visible') refresh(); };
  onMounted(() => {
    timer = window.setInterval(refresh, 5000);
    window.addEventListener('online', refresh);
    window.addEventListener('focus', refresh);
    document.addEventListener('visibilitychange', visible);
  });
  onBeforeUnmount(() => {
    disposed = true;
    window.clearInterval(timer);
    window.removeEventListener('online', refresh);
    window.removeEventListener('focus', refresh);
    document.removeEventListener('visibilitychange', visible);
  });
  return refresh;
}
