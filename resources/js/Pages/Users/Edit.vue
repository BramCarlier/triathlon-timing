<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import RolePermissions from '../../Components/RolePermissions.vue';
import type { UserRole } from '../../types';

interface Athlete { id: number; first_name: string; last_name: string; email?: string }
interface Race { id: number; name: string; event_date: string }
interface Account { id: number; name: string; email: string; role: UserRole; is_active: boolean; athlete_id: number | null; races: Race[] }
const props = defineProps<{ account: Account; athletes: Athlete[]; races: Race[] }>();
const form = useForm({
  name: props.account.name,
  email: props.account.email,
  role: props.account.role,
  is_active: props.account.is_active,
  athlete_id: props.account.athlete_id,
  race_ids: props.account.races.map(race => race.id),
  password: '',
});
watch(() => form.role, role => {
  if (role !== 'athlete') form.athlete_id = null;
  if (role !== 'organizer') form.race_ids = [];
});
const save = () => form.put(`/users/${props.account.id}`, { preserveScroll: true });
</script>
<template>
  <Head :title="`Edit ${account.name}`" />
  <AppLayout title="Edit user & access">
    <Link href="/users" class="btn-secondary mb-5">Back to users</Link>
    <form class="grid gap-5 lg:grid-cols-2" @submit.prevent="save">
      <section class="panel-pad space-y-4">
        <h2 class="text-lg font-bold">Account details</h2>
        <div><label for="user-name" class="label">Name</label><input id="user-name" v-model="form.name" class="field" required maxlength="255"></div>
        <div><label for="user-email" class="label">Email</label><input id="user-email" v-model="form.email" type="email" class="field" required maxlength="255"></div>
        <label class="flex items-center gap-3"><input v-model="form.is_active" type="checkbox">Account active</label>
        <p class="text-sm muted">Disabling an account blocks sign-in and ends access from existing sessions on their next request.</p>
        <div><label for="user-password" class="label">New temporary password (optional)</label><input id="user-password" v-model="form.password" type="password" class="field" autocomplete="new-password" minlength="12"><p class="mt-1 text-xs muted">Leave blank to keep the password. A new password must have at least 12 characters and must be changed at the next sign-in.</p></div>
      </section>
      <section class="panel-pad space-y-4">
        <h2 class="text-lg font-bold">Role & permissions</h2>
        <div><label for="user-role" class="label">Role</label><select id="user-role" v-model="form.role" class="field"><option value="admin">Administrator</option><option value="organizer">Organizer</option><option value="athlete">Athlete</option></select></div>
        <RolePermissions :role="form.role" />
        <div v-if="form.role==='organizer'">
          <h3 class="label">Assigned races</h3>
          <p class="mb-3 text-sm muted">Select the races this organizer can manage. Access changes when you save.</p>
          <div class="max-h-72 space-y-3 overflow-auto rounded-xl border border-slate-800 p-3">
            <label v-for="race in races" :key="race.id" class="flex gap-3 text-sm"><input v-model="form.race_ids" type="checkbox" :value="race.id"><span>{{ race.name }} <span class="muted">· {{ race.event_date }}</span></span></label>
            <p v-if="!races.length" class="muted">No races available.</p>
          </div>
        </div>
        <div v-if="form.role==='athlete'"><label for="user-athlete" class="label">Linked athlete</label><select id="user-athlete" v-model="form.athlete_id" class="field" required><option :value="null">Select athlete…</option><option v-for="athlete in athletes" :key="athlete.id" :value="athlete.id">{{ athlete.last_name }}, {{ athlete.first_name }}{{ athlete.email ? ` · ${athlete.email}` : '' }}</option></select></div>
      </section>
      <div class="lg:col-span-2">
        <ul v-if="Object.keys(form.errors).length" role="alert" class="mb-4 list-disc rounded-xl border border-red-500/30 bg-red-500/10 p-4 pl-8 text-red-200"><li v-for="(error, field) in form.errors" :key="field">{{ error }}</li></ul>
        <div class="flex gap-3"><button class="btn-primary" :disabled="form.processing">Save user</button><Link href="/users" class="btn-secondary">Cancel</Link></div>
      </div>
    </form>
  </AppLayout>
</template>
