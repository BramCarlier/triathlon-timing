<script setup lang="ts">
import { formatDate } from '../../presentation';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { watch } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import AthletePicker from '../../Components/AthletePicker.vue';
import type { UserRole } from '../../types';

interface AccessRole { id:number; name:string; description:string|null; permissions:string[]; is_default:boolean }
interface Athlete { id:number; first_name:string; last_name:string; email?:string }
interface Race { id:number; name:string; event_date:string }
interface Account { id:number; name:string; email:string; role:UserRole; access_role_id:number|null; is_active:boolean; force_password_change:boolean; invitation_sent_at?:string; athlete_id:number|null; races:Race[] }

const props=defineProps<{accessRoles:AccessRole[];account:Account;linkedAthlete:Athlete|null;races:Race[];mailConfigured:boolean}>();
const form=useForm({
  name:props.account.name,email:props.account.email,role:props.account.role,access_role_id:props.account.access_role_id,
  is_active:props.account.is_active,athlete_id:props.account.athlete_id,race_ids:props.account.races.map(r=>r.id),password:'',
});
watch(()=>form.role,role=>{if(role!=='athlete')form.athlete_id=null;if(role!=='organizer'){form.race_ids=[];form.access_role_id=null;}});
const save=()=>form.put(`/users/${props.account.id}`,{preserveScroll:true});
</script>

<template>
  <Head :title="`Edit ${account.name}`"/>
  <AppLayout :title="$t('Edit person & access')">
    <Link href="/users" class="btn-secondary mb-5"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>{{ $t("Back to People") }}</Link>

    <section v-if="account.force_password_change" class="panel-pad mb-5">
      <div class="flex flex-col gap-3 sm:flex-row sm:items-center sm:justify-between">
        <div><h2 class="font-bold">{{ $t("Account setup pending") }}</h2><p class="mt-1 text-sm muted">{{ account.invitation_sent_at?$t('An invitation was sent. You can send a fresh link if needed.'):$t('This user must choose a password before using the app.') }}</p></div>
        <button type="button" class="btn-secondary" :disabled="!mailConfigured || !account.is_active" @click="router.post(`/users/${account.id}/invitation`)"><i class="fa-solid fa-envelope" aria-hidden="true"></i>{{ $t("Resend invitation") }}</button>
      </div>
    </section>

    <form class="grid gap-5 lg:grid-cols-2" @submit.prevent="save">
      <section class="panel-pad space-y-4">
        <h2 class="text-lg font-bold">{{ $t("Account") }}</h2>
        <label class="label">{{ $t("Name") }}<input v-model="form.name" class="field" required maxlength="255"></label>
        <label class="label">{{ $t("Email") }}<input v-model="form.email" type="email" class="field" required maxlength="255"></label>
        <label class="label">{{ $t("Account type") }}<select v-model="form.role" class="field"><option value="admin">{{ $t("Organizer (admin)") }}</option><option value="organizer">{{ $t("Official") }}</option><option value="athlete">{{ $t("Athlete") }}</option></select></label>
        <label class="flex items-center gap-3"><input v-model="form.is_active" type="checkbox"><span><strong class="block text-sm">{{ $t("Account active") }}</strong><span class="text-xs muted">{{ $t("Disabled accounts cannot sign in.") }}</span></span></label>
        <details class="rounded-xl border border-outline p-3">
          <summary class="font-semibold">{{ $t("Password options") }}</summary>
          <label class="label mt-3">{{ $t("New temporary password") }} <span class="font-normal muted">{{ $t("(optional)") }}</span><input v-model="form.password" type="password" class="field" autocomplete="new-password" minlength="12"></label>
          <p class="mt-2 text-xs muted">{{ $t("Leave blank to keep the current password. A new temporary password must be changed at the next sign-in.") }}</p>
        </details>
      </section>

      <section class="panel-pad space-y-4">
        <h2 class="text-lg font-bold">{{ form.role==='athlete'?'Athlete profile':'Access' }}</h2>
        <AthletePicker v-if="form.role==='athlete'" v-model="form.athlete_id" :account-id="account.id" :initial="linkedAthlete"/>
        <template v-else-if="form.role==='organizer'">
          <p class="text-sm muted">{{ $t("Checkpoint assignments normally grant race access automatically. Only change the options below for exceptional access.") }}</p>
          <details class="rounded-xl border border-outline p-3">
            <summary class="font-semibold">{{ $t("Advanced Official access") }}</summary>
            <label class="label mt-3">{{ $t("Access preset") }}<select v-model="form.access_role_id" class="field"><option :value="null">{{ $t("Official (default)") }}</option><option v-for="role in accessRoles.filter(r=>!r.is_default)" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
            <p class="mt-2 text-xs muted">{{ (form.access_role_id?accessRoles.find(r=>r.id===form.access_role_id):accessRoles.find(r=>r.is_default))?.description }}</p>
            <h3 class="mt-4 text-sm font-semibold">{{ $t("Extra race access") }}</h3>
            <p class="mt-1 text-xs muted">{{ $t("Use only when this Official needs race access without a checkpoint assignment.") }}</p>
            <div class="mt-3 max-h-56 space-y-2 overflow-auto rounded-xl bg-canvas p-3">
              <label v-for="race in races" :key="race.id" class="flex gap-3 text-sm"><input v-model="form.race_ids" type="checkbox" :value="race.id"><span>{{ race.name }} <span class="muted">· {{ formatDate(race.event_date) }}</span></span></label>
              <p v-if="!races.length" class="muted">{{ $t("No races available.") }}</p>
            </div>
            <Link href="/admin/roles" class="btn-secondary mt-3"><i class="fa-solid fa-shield-halved" aria-hidden="true"></i>{{ $t("Manage access presets") }}</Link>
          </details>
        </template>
        <div v-else class="rounded-xl bg-canvas p-4 text-sm muted">Organizer (admin) accounts have full access to all races and administration.</div>
      </section>

      <div class="lg:col-span-2">
        <ul v-if="Object.keys(form.errors).length" role="alert" class="mb-4 list-disc rounded-xl border border-red-500/30 bg-red-500/10 p-4 pl-8 text-error"><li v-for="(error,field) in form.errors" :key="field">{{ error }}</li></ul>
        <div class="flex flex-wrap gap-3"><button class="btn-primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>Save changes</button><Link href="/users" class="btn-secondary">Cancel</Link></div>
      </div>
    </form>
  </AppLayout>
</template>
