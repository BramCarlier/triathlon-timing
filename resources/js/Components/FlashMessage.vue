<script setup lang="ts">
defineProps<{ success?: string; error?: string }>();
</script>
<template>
  <div v-if="success" role="status" class="mb-4 rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-success">{{ success }}</div>
  <div v-if="error" role="alert" class="mb-4 rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-error">{{ error }}</div>
</template>
