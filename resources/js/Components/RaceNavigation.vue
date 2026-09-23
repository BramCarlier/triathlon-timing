<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { usePermissions } from '../Composables/usePermissions';
import { computed } from 'vue';
import type { Race } from '../types';
const props = defineProps<{ race: Race }>();
const page = usePage();
const can=usePermissions();
const links = computed(() => [
  { label: 'Overview & setup', href: `/races/${props.race.id}` },
  { permission:'participants.manage', label: 'Participants', href: `/races/${props.race.id}/participants` },
  { permission:'timings.record', label: 'Timing station', href: `/races/${props.race.id}/station` },
  { permission:'races.control', label: 'Race control', href: `/races/${props.race.id}/control` },
  { label: 'Results', href: `/races/${props.race.id}/results` },
].filter(link=>!link.permission || can(link.permission)));
const active = (href: string) => page.url.split('?')[0] === href || (href.endsWith('/participants') && page.url.startsWith(`${href}/`));
</script>
<template>
  <nav aria-label="Race navigation" class="mb-6 rounded-2xl border border-outline bg-canvas/70 p-2">
    <div class="grid grid-cols-2 gap-1 sm:flex sm:flex-wrap sm:items-center">
      <Link href="/races" class="min-h-11 rounded-xl px-3 py-3 text-sm text-muted hover:bg-raised">All races</Link>
      <Link v-for="link in links" :key="link.href" :href="link.href" :aria-current="active(link.href) ? 'page' : undefined"
        class="min-h-11 rounded-xl px-3 py-3 text-sm font-semibold transition-colors"
        :class="active(link.href) ? 'bg-cyan-400 text-slate-950' : 'text-secondary hover:bg-raised'">{{ link.label }}</Link>
    </div>
  </nav>
</template>
