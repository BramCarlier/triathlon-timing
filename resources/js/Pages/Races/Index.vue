<script setup lang="ts">
import { tr } from '../../i18n';
import { usePermissions } from '../../Composables/usePermissions';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import RaceClock from '../../Components/RaceClock.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
import { formatDate } from '../../presentation';
import type { PageProps, Race } from '../../types';

const can=usePermissions();
const page=usePage<PageProps>();
const isAdmin=page.props.auth.user?.role==='admin';
defineProps<{races:Race[];deletedRaces:Race[];serverNow:string}>();
useRaceRefresh(()=>['races','serverNow']);

const statusLabel=(race:Race)=>{
  if(race.finished_at)return tr('Finished');
  if(race.started_at)return tr('Live now');
  return race.status==='ready'?tr('Ready for race day'):tr('Being prepared');
};
</script>

<template>
  <Head :title="$t('Races')"/>
  <AppLayout :title="$t('Races')">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <div>
        <p class="font-semibold">{{ isAdmin?$t('Choose a race and continue in its guided workspace.'):$t('Choose a race to open your assigned timing station.') }}</p>
        <p class="mt-1 text-sm muted">{{ isAdmin?$t('Athletes, checkpoints, the shared race-day link and the race clock stay together. Official accounts are optional.'):$t('Your checkpoint assignment follows your account automatically.') }}</p>
      </div>
      <Link v-if="can('races.create')" href="/races/create" class="btn-primary">
        <i class="fa-solid fa-plus" aria-hidden="true"></i>{{ $t("Create race") }}
      </Link>
    </div>

    <div class="grid gap-5 md:grid-cols-2 xl:grid-cols-3">
      <article v-for="race in races" :key="race.id" class="panel-pad flex flex-col gap-4">
        <div class="flex items-start justify-between gap-3">
          <div>
            <span class="badge" :data-status="race.status">{{ statusLabel(race) }}</span>
            <h2 class="mt-3 text-xl font-bold">{{ race.name }}</h2>
            <p class="mt-1 text-sm muted">{{ formatDate(race.event_date) }} · {{ race.entries_count??0 }} {{ $t("participants") }}</p>
          </div>
        </div>
        <div v-if="race.started_at" class="rounded-xl bg-canvas p-4">
          <div class="mb-1 text-xs font-semibold uppercase tracking-widest text-accent">{{ race.finished_at?$t('Final race time'):$t('Shared race clock') }}</div>
          <RaceClock :started-at="race.started_at" :finished-at="race.finished_at" :server-now="serverNow" compact/>
        </div>
        <Link :href="isAdmin?`/races/${race.id}`:`/races/${race.id}/station`" class="btn-primary mt-auto">
          <i :class="isAdmin?'fa-solid fa-arrow-right':'fa-solid fa-stopwatch'" aria-hidden="true"></i>{{ isAdmin?(race.finished_at?$t('Open finished race'):$t('Open race')):$t('Open timing station') }}
        </Link>
      </article>
    </div>

    <section v-if="!races.length" class="panel-pad text-center">
      <h2 class="text-xl font-bold">{{ $t("Your first race starts here") }}</h2>
      <p class="mx-auto mt-3 max-w-lg muted">{{ $t("Create a race to get one guided workspace for setup and race day.") }}</p>
      <Link v-if="can('races.create')" href="/races/create" class="btn-primary mt-5">
        <i class="fa-solid fa-plus" aria-hidden="true"></i>{{ $t("Create your first race") }}
      </Link>
    </section>

    <details v-if="deletedRaces.length" class="panel-pad mt-6">
      <summary class="cursor-pointer font-bold">{{ $t("Deleted races ·") }} {{ deletedRaces.length }}</summary>
      <p class="mt-2 text-sm muted">{{ $t("These are hidden from normal race work but can still be restored.") }}</p>
      <div v-for="race in deletedRaces" :key="race.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-outline py-4 last:border-0">
        <div><h3 class="font-semibold">{{ race.name }}</h3><p class="text-sm muted">{{ formatDate(race.event_date) }}</p></div>
        <button class="btn-secondary" @click="router.post(`/races/${race.id}/restore`)">
          <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>{{ $t("Restore") }}
        </button>
      </div>
    </details>
  </AppLayout>
</template>
