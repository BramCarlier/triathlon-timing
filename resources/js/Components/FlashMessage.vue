<script setup lang="ts">
defineProps<{ success?: string; error?: string }>();
</script>
<template>
  <div v-if="success" class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-emerald-200">{{ success }}</div>
  <div v-if="error" class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-red-200">{{ error }}</div>
</template>
