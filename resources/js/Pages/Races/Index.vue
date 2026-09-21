<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Race } from '../../types';
defineProps<{ races: Race[] }>();
</script>
<template><Head title="Races"/><AppLayout title="Races"><div class="mb-5 flex justify-end"><Link href="/races/create" class="btn-primary">Create race</Link></div><div class="panel overflow-hidden"><div v-for="race in races" :key="race.id" class="flex flex-col gap-3 border-b border-slate-800 p-4 last:border-0 sm:flex-row sm:items-center"><div class="min-w-0 flex-1"><div class="flex items-center gap-2"><h2 class="font-bold">{{ race.name }}</h2><span class="badge">{{ race.status }}</span></div><p class="muted text-sm">{{ race.event_date }} · {{ race.entries_count ?? 0 }} entries</p></div><div class="flex gap-2"><Link :href="`/races/${race.id}`" class="btn-secondary">Setup</Link><Link :href="`/races/${race.id}/control`" class="btn-primary">Control</Link></div></div></div></AppLayout></template>
