<script setup lang="ts">
import Icon from './Icon.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PageProps, Race } from '../types';

const props = defineProps<{ race: Race }>();
const page = usePage<PageProps>();
const path = computed(() => page.url.split('?')[0]);
const isAdmin = page.props.auth.user?.role === 'admin';
const base = `/races/${props.race.id}`;
const tabs = computed(() => [
  isAdmin
    ? { href: base, label: 'Race workspace', icon: 'gauge-high', current: path.value === base }
    : { href: `${base}/station`, label: 'Timing station', icon: 'stopwatch', current: path.value === `${base}/station` },
  ...(isAdmin
    ? [{ href: `${base}/participants`, label: 'Athletes', icon: 'users', current: path.value.startsWith(`${base}/participants`) }]
    : []),
  { href: `${base}/results`, label: 'Results', icon: 'trophy', current: path.value === `${base}/results` },
]);
</script>

<template>
  <nav :aria-label="$t('Race navigation')" class="mb-5 flex flex-wrap items-center gap-x-1 gap-y-2 border-b border-outline">
    <Link href="/races" class="btn-quiet !min-h-11 !px-2 text-sm">
      <Icon name="chevron-left" />
      {{ $t('All races') }}
    </Link>
    <span class="mx-1 hidden h-5 w-px bg-outline sm:block" aria-hidden="true"></span>
    <Link
      v-for="tab in tabs"
      :key="tab.href"
      :href="tab.href"
      :aria-current="tab.current ? 'page' : undefined"
      class="-mb-px inline-flex min-h-11 items-center gap-2 border-b-3 px-3 font-semibold transition-colors"
      :class="tab.current ? 'border-buoy text-foreground' : 'border-transparent text-muted hover:text-foreground'"
    >
      <Icon :name="tab.icon" />
      {{ $t(tab.label) }}
    </Link>
  </nav>
</template>
