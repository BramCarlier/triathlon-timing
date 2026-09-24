<script setup lang="ts">
import { formatDateTime } from '../../presentation';
import { Head } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import { useRaceRefresh } from '../../Composables/useRaceRefresh';
defineProps<{checks:{name:string;ok:boolean;detail:string}[];checkedAt:string}>();
useRaceRefresh(()=>['checks','checkedAt']);
</script>
<template><Head :title="$t('System health')"/><AppLayout :title="$t('System health')"><p class="muted mb-4">{{ $t("Last checked") }} {{ formatDateTime(checkedAt) }}{{ $t(". This screen refreshes automatically. External uptime alerts and off-server backups must be configured separately.") }}</p><div class="grid gap-3 md:grid-cols-2"><section v-for="check in checks" :key="check.name" class="panel-pad"><div class="flex flex-wrap justify-between gap-3"><h2 class="font-bold">{{ check.name }}</h2><span :class="check.ok?'text-success':'text-warning'">{{ check.ok?'OK':$t('Needs attention') }}</span></div><p class="mt-2 muted">{{ check.detail }}</p></section></div></AppLayout></template>
