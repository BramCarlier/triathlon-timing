<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Race } from '../../types';
defineProps<{ races: Race[]; deletedRaces: Race[] }>();
</script>
<template><Head title="Races"/><AppLayout title="Races"><div class="mb-5 flex justify-end"><Link href="/races/create" class="btn-primary">Create race</Link></div><div class="panel overflow-hidden"><div v-for="race in races" :key="race.id" class="flex flex-col gap-3 border-b border-slate-800 p-4 last:border-0 sm:flex-row sm:items-center"><div class="min-w-0 flex-1"><div class="flex items-center gap-2"><h2 class="font-bold">{{ race.name }}</h2><span class="badge">{{ race.status }}</span></div><p class="muted text-sm">{{ race.event_date }} · {{ race.entries_count ?? 0 }} entries</p></div><div class="flex gap-2"><Link :href="`/races/${race.id}`" class="btn-secondary">Edit race</Link><Link :href="`/races/${race.id}/control`" class="btn-primary">Control</Link></div></div></div>
<section v-if="deletedRaces.length" class="panel mt-6 overflow-hidden"><div class="border-b border-slate-800 p-4"><h2 class="font-bold">Deleted races</h2><p class="mt-1 text-sm muted">Restore a race with all its participants, timings and assignments.</p></div><div v-for="race in deletedRaces" :key="race.id" class="flex flex-wrap items-center justify-between gap-3 border-b border-slate-800 p-4 last:border-0"><div><h3 class="font-semibold">{{ race.name }}</h3><p class="text-sm muted">{{ race.event_date }}</p></div><button class="btn-secondary" @click="router.post(`/races/${race.id}/restore`)">Restore race</button></div></section>
<p v-if="!races.length" class="panel-pad text-center muted">No active races. Create a race to begin.</p>
</AppLayout></template>
