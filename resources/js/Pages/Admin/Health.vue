<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
defineProps<{checks:{name:string;ok:boolean;detail:string}[];checkedAt:string}>();
useRaceRefresh(()=>['checks','checkedAt']);
</script>
<template><Head title="System health"/><AppLayout title="System health"><p class="muted mb-4">Last checked {{ new Date(checkedAt).toLocaleString() }}. This screen refreshes automatically. External uptime alerts and off-server backups must be configured separately.</p><div class="grid gap-3 md:grid-cols-2"><section v-for="check in checks" :key="check.name" class="panel-pad"><div class="flex justify-between gap-3"><h2 class="font-bold">{{ check.name }}</h2><span :class="check.ok?'text-emerald-300':'text-amber-200'">{{ check.ok?'OK':'Needs attention' }}</span></div><p class="mt-2 muted">{{ check.detail }}</p></section></div></AppLayout></template>
