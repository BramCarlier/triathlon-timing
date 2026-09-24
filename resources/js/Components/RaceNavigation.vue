<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import type { PageProps, Race } from '../types';

const props = defineProps<{ race: Race }>();
const page = usePage<PageProps>();
const active = (href: string) => page.url.split('?')[0] === href;
const isAdmin = page.props.auth.user?.role === 'admin';
const primaryHref = isAdmin ? `/races/${props.race.id}` : `/races/${props.race.id}/station`;
</script>

<template>
  <nav :aria-label="$t('Race navigation')" class="mb-6 rounded-2xl border border-outline bg-canvas/70 p-2">
    <div class="flex flex-wrap items-center gap-1">
      <Link href="/races" class="min-h-11 rounded-xl px-3 py-3 text-sm text-muted hover:bg-raised">
        <i class="fa-solid fa-chevron-left mr-2" aria-hidden="true"></i>{{ $t("All races") }}
      </Link>
      <Link
        :href="primaryHref"
        :aria-current="active(primaryHref) ? 'page' : undefined"
        class="min-h-11 rounded-xl px-3 py-3 text-sm font-semibold transition-colors"
        :class="active(primaryHref) ? 'bg-cyan-400 text-slate-950' : 'text-secondary hover:bg-raised'"
      >
        <i :class="isAdmin?'fa-solid fa-gauge-high':'fa-solid fa-stopwatch'" class="mr-2" aria-hidden="true"></i>{{ isAdmin?$t('Race workspace'):$t('Timing station') }}
      </Link>
      <Link
        :href="`/races/${props.race.id}/results`"
        :aria-current="active(`/races/${props.race.id}/results`) ? 'page' : undefined"
        class="min-h-11 rounded-xl px-3 py-3 text-sm font-semibold transition-colors"
        :class="active(`/races/${props.race.id}/results`) ? 'bg-cyan-400 text-slate-950' : 'text-secondary hover:bg-raised'"
      >
        <i class="fa-solid fa-trophy mr-2" aria-hidden="true"></i>{{ $t("Results") }}
      </Link>
    </div>
  </nav>
</template>
