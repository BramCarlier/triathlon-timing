<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { formatDate, formatDateTime } from '../../presentation';

interface DeletedRace { id:number; name:string; event_date:string; deleted_at:string; entries_count:number }
defineProps<{ deletedRaces:{data:DeletedRace[];current_page:number;last_page:number;prev_page_url:string|null;next_page_url:string|null} }>();
const form=useForm({});
const restore=(race:DeletedRace)=>form.post(`/races/${race.id}/restore`);
</script>

<template>
  <Head :title="$t('Deleted races')"/>
  <AppLayout :title="$t('Deleted races')">
    <div class="mb-5 flex flex-wrap items-center justify-between gap-3">
      <p class="max-w-2xl text-sm muted">{{ $t('Only organizers (admins) can view this page. Restore a race to return it to the race list with its participants and timings.') }}</p>
      <Link href="/races" class="btn-secondary"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>{{ $t('All races') }}</Link>
    </div>
    <p v-if="Object.keys(form.errors).length" role="alert" class="mb-4 text-sm text-error">{{ Object.values(form.errors)[0] }}</p>
    <section class="panel overflow-hidden">
      <div class="divide-y divide-outline">
        <article v-for="race in deletedRaces.data" :key="race.id" class="flex flex-col items-start gap-3 p-4 sm:flex-row sm:items-center sm:justify-between">
          <div class="min-w-0 break-words">
            <h2 class="font-bold">{{ race.name }}</h2>
            <p class="mt-1 text-sm muted">{{ formatDate(race.event_date) }} · {{ race.entries_count }} {{ $t('participants') }}</p>
            <p class="mt-1 text-sm muted">{{ $t('Deleted on :date', {date:formatDateTime(race.deleted_at)}) }}</p>
          </div>
          <button class="btn-secondary shrink-0" :disabled="form.processing" :aria-label="$t('Restore :name', {name:race.name})" @click="restore(race)">
            <i class="fa-solid fa-rotate-left" aria-hidden="true"></i>{{ $t('Restore') }}
          </button>
        </article>
        <p v-if="!deletedRaces.data.length" class="p-8 text-center muted">{{ $t('No deleted races.') }}</p>
      </div>
      <nav v-if="deletedRaces.last_page>1" :aria-label="$t('Deleted race pages')" class="flex flex-wrap items-center justify-between gap-3 border-t border-outline p-4">
        <Link v-if="deletedRaces.prev_page_url" :href="deletedRaces.prev_page_url" class="btn-secondary"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i>{{ $t('Previous') }}</Link><span v-else></span>
        <span class="text-sm muted">{{ $t('Page') }} {{ deletedRaces.current_page }} {{ $t('of') }} {{ deletedRaces.last_page }}</span>
        <Link v-if="deletedRaces.next_page_url" :href="deletedRaces.next_page_url" class="btn-secondary">{{ $t('Next') }}<i class="fa-solid fa-chevron-right" aria-hidden="true"></i></Link><span v-else></span>
      </nav>
    </section>
  </AppLayout>
</template>
