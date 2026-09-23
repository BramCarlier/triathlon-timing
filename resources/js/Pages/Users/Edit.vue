<script setup lang="ts">
import { formatDate } from '../../presentation';
import { Head, Link, useForm, router } from '@inertiajs/vue3';
import { watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import AthletePicker from '../../Components/AthletePicker.vue';
import RolePermissions from '../../Components/RolePermissions.vue';
import type { UserRole } from '../../types';

interface AccessRole { id:number; name:string; description:string|null; permissions:string[]; is_default:boolean }
interface Athlete { id: number; first_name: string; last_name: string; email?: string }
interface Race { id: number; name: string; event_date: string }
interface Account { id: number; name: string; email: string; role: UserRole; access_role_id:number|null; is_active: boolean; force_password_change:boolean; invitation_sent_at?:string; athlete_id: number | null; races: Race[] }
const props = defineProps<{ accessRoles:AccessRole[];  account: Account; linkedAthlete: Athlete|null; races: Race[]; mailConfigured:boolean }>();
const form = useForm({
  name: props.account.name,
  email: props.account.email,
  role: props.account.role,
  access_role_id:props.account.access_role_id,
  is_active: props.account.is_active,
  athlete_id: props.account.athlete_id,
  race_ids: props.account.races.map(race => race.id),
  password: '',
});
watch(() => form.role, role => {
  if (role !== 'athlete') form.athlete_id = null;
  if (role !== 'organizer') { form.race_ids = []; form.access_role_id=null; }
});
const save = () => form.put(`/users/${props.account.id}`, { preserveScroll: true });
</script>
<template>
  <Head :title="`Edit ${account.name}`" />
  <AppLayout title="Edit user & access">
    <Link href="/users" class="btn-secondary mb-5">Back to users</Link>
    <section v-if="account.force_password_change" class="panel-pad mb-5"><h2 class="font-bold">Password setup pending</h2><p class="mt-2 muted">{{ account.invitation_sent_at?'An invitation was accepted by the mail server. You can send a fresh link if needed.':'This user must choose a password before using the app.' }}</p><button type="button" class="btn-secondary mt-3" :disabled="!mailConfigured || !account.is_active" @click="router.post(`/users/${account.id}/invitation`)">Resend invitation</button><p v-if="!mailConfigured" class="mt-2 text-sm text-warning">Connect email sending to send invitations.</p></section><form class="grid gap-5 lg:grid-cols-2" @submit.prevent="save">
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
        <div><label for="user-role" class="label">Role</label><select id="user-role" v-model="form.role" class="field"><option value="admin">Organizer (admin)</option><option value="organizer">Official</option><option value="athlete">Athlete</option></select></div>
        <div v-if="form.role==='organizer'"><label for="access-role" class="label">Official role</label><select id="access-role" v-model="form.access_role_id" class="field"><option :value="null">Official (default)</option><option v-for="accessRole in accessRoles.filter(r=>!r.is_default)" :key="accessRole.id" :value="accessRole.id">{{ accessRole.name }}</option></select><p class="mt-2 text-sm muted">{{ (form.access_role_id?accessRoles.find(r=>r.id===form.access_role_id):accessRoles.find(r=>r.is_default))?.description }}</p><Link href="/admin/roles" class="mt-2 inline-block text-sm text-accent underline">Manage roles & permissions</Link></div><RolePermissions :role="form.role"/>
        <div v-if="form.role==='organizer'">
          <h3 class="label">Assigned races</h3>
          <p class="mb-3 text-sm muted">Select the races this official can manage. Access changes when you save.</p>
          <div class="max-h-72 space-y-3 overflow-auto rounded-xl border border-outline p-3">
            <label v-for="race in races" :key="race.id" class="flex flex-wrap gap-3 text-sm"><input v-model="form.race_ids" type="checkbox" :value="race.id"><span>{{ race.name }} <span class="muted">· {{ formatDate(race.event_date) }}</span></span></label>
            <p v-if="!races.length" class="muted">No races available.</p>
          </div>
        </div>
        <AthletePicker v-if="form.role==='athlete'" v-model="form.athlete_id" :account-id="account.id" :initial="linkedAthlete"/>
      </section>
      <div class="lg:col-span-2">
        <ul v-if="Object.keys(form.errors).length" role="alert" class="mb-4 list-disc rounded-xl border border-red-500/30 bg-red-500/10 p-4 pl-8 text-error"><li v-for="(error, field) in form.errors" :key="field">{{ error }}</li></ul>
        <div class="flex flex-wrap gap-3"><button class="btn-primary" :disabled="form.processing">Save user</button><Link href="/users" class="btn-secondary">Cancel</Link></div>
      </div>
    </form>
  </AppLayout>
</template>
