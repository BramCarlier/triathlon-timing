<script setup lang="ts">
defineProps<{ success?: string; error?: string }>();
</script>
<template>
  <div v-if="success" role="status" class="mb-4 rounded-md border border-success/40 bg-success/10 px-4 py-3 text-success">
    {{ success }}
  </div>
  <div v-if="error" role="alert" class="mb-4 rounded-md border border-error/40 bg-error/10 px-4 py-3 text-error">{{ error }}</div>
</template>
