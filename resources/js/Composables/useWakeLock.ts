import { onBeforeUnmount, ref } from 'vue';
import { tr } from '../i18n';
export function useWakeLock() {
  const enabled=ref(false),active=ref(false),error=ref('');let lock:WakeLockSentinel|undefined;
  const acquire=async()=>{if(!enabled.value||document.visibilityState!=='visible')return;try{lock=await navigator.wakeLock.request('screen');active.value=true;lock.addEventListener('release',()=>{active.value=false;});error.value='';}catch{active.value=false;error.value=tr('Screen wake lock is unavailable. Check your device power settings.');}};
  const toggle=async()=>{enabled.value=!enabled.value;if(enabled.value)await acquire();else {await lock?.release();active.value=false;}};
  document.addEventListener('visibilitychange',acquire);
  onBeforeUnmount(()=>{document.removeEventListener('visibilitychange',acquire);void lock?.release();});
  return {enabled,active,error,toggle,supported:'wakeLock' in navigator};
}
