<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { Race } from '../types';
const props = defineProps<{ race: Race }>();
const page = usePage();
const links = computed(() => [
  { label: 'Race settings', href: `/races/${props.race.id}` },
  { label: 'Participants', href: `/races/${props.race.id}/participants` },
  { label: 'Timing station', href: `/races/${props.race.id}/station` },
  { label: 'Race control', href: `/races/${props.race.id}/control` },
  { label: 'Results', href: `/races/${props.race.id}/results` },
]);
const active = (href: string) => page.url.split('?')[0] === href || (href.endsWith('/participants') && page.url.startsWith(`${href}/`));
</script>
<template>
  <nav aria-label="Race navigation" class="mb-6 rounded-2xl border border-slate-800 bg-slate-950/70 p-2">
    <div class="flex flex-wrap items-center gap-1">
      <Link href="/races" class="rounded-xl px-3 py-3 text-sm text-slate-400 hover:bg-slate-800">All races</Link>
      <Link v-for="link in links" :key="link.href" :href="link.href" :aria-current="active(link.href) ? 'page' : undefined"
        class="rounded-xl px-3 py-3 text-sm font-semibold transition-colors"
        :class="active(link.href) ? 'bg-cyan-400 text-slate-950' : 'text-slate-300 hover:bg-slate-800'">{{ link.label }}</Link>
    </div>
  </nav>
</template>
