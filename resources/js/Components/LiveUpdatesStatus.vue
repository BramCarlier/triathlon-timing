<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref } from 'vue';
const props = defineProps<{ raceId: number }>();
const state = ref<'connecting' | 'live' | 'refresh'>('connecting');
let connection: any;
let subscription: any;
const subscribed = () => { state.value = 'live'; };
const failed = (error?: any) => {
  state.value = 'refresh';
  console.warn('Live race updates unavailable', { status: error?.status, code: error?.error?.data?.code, type: error?.type });
};
const changed = ({current}: {current: string}) => {
  state.value = current === 'connected' ? (subscription?.subscribed ? 'live' : 'connecting') : 'refresh';
};
onMounted(() => {
  const echo = (window as any).Echo;
  if (!echo) { state.value = 'refresh'; return; }
  connection = echo.connector.pusher.connection;
  subscription = echo.private(`race.${props.raceId}`).subscription;
  subscription.bind('pusher:subscription_succeeded', subscribed);
  subscription.bind('pusher:subscription_error', failed);
  connection.bind('state_change', changed);
  connection.bind('error', failed);
  changed({current: connection.state});
});
onBeforeUnmount(() => {
  subscription?.unbind('pusher:subscription_succeeded', subscribed);
  subscription?.unbind('pusher:subscription_error', failed);
  connection?.unbind('state_change', changed);
  connection?.unbind('error', failed);
});
</script>
<template>
  <span role="status" :aria-label="$t('Live updates')" class="text-xs font-semibold" :class="state==='live' ? 'text-success' : 'text-warning'">
    {{ state==='live' ? $t('Live updates connected') : state==='connecting' ? $t('Connecting live updates…') : $t('Using automatic refresh · live updates reconnecting') }}
  </span>
</template>
