<script setup lang="ts">
defineProps<{ role: string }>();
</script>
<template>
  <section aria-label="Role permissions" class="rounded-xl border border-outline-strong bg-canvas p-4 text-sm">
    <h3 class="font-semibold">Permissions for this role</h3>
    <p v-if="role==='admin'" class="mt-2 text-secondary">Manage all users and roles. Create, edit, delete and restore any race. Manage participants, checkpoints, timing and results in every race.</p>
    <p v-else-if="role==='organizer'" class="mt-2 text-secondary">Create races and manage assigned races: settings, participants, checkpoints, shared clock, timing corrections and results exports. Cannot manage users or delete races.</p>
    <p v-else class="mt-2 text-secondary">View the linked athlete’s races and results. Cannot edit participants, record timings, manage races or manage users.</p>
  </section>
</template>
