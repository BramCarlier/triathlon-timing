<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch } from 'vue';
import AthletePicker from '../../Components/AthletePicker.vue';
import AppLayout from '../../Layouts/AppLayout.vue';

interface AccessRole { id:number; name:string; description:string|null; permissions:string[]; is_default:boolean }
interface Athlete { id:number; first_name:string; last_name:string; email?:string }
interface Race { id:number; name:string; event_date:string }
interface User { id:number; name:string; email:string; role:'admin'|'organizer'|'athlete'; access_role?:AccessRole; is_active:boolean;force_password_change:boolean;invitation_sent_at?:string; athlete_id?:number; athlete?:Athlete; races:Race[] }

const props = defineProps<{ accessRoles:AccessRole[]; users:{data:User[];current_page:number;last_page:number;prev_page_url:string|null;next_page_url:string|null;total:number}; filters:{q:string}; races:Race[]; mailConfigured:boolean }>();
const search=ref(props.filters.q);
const searchUsers=()=>router.get('/users',{q:search.value},{preserveState:true,preserveScroll:true});
const form=useForm({name:'',email:'',password:'',delivery:props.mailConfigured?'email':'manual',role:'organizer',access_role_id:null as number|null,athlete_id:null as number|null,race_ids:[] as number[]});
watch(()=>form.role,role=>{if(role!=='athlete')form.athlete_id=null;if(role!=='organizer'){form.race_ids=[];form.access_role_id=null;}});
const submit=()=>form.post('/users',{preserveScroll:true,onSuccess:()=>{form.reset();form.delivery=props.mailConfigured?'email':'manual';}});
const roleLabel=(user:User)=>user.role==='admin'?'Organizer (admin)':user.role==='athlete'?'Athlete':user.access_role?.name??'Official';
</script>

<template>
  <Head title="People & access"/>
  <AppLayout title="People & access">
    <div class="mb-5 flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
      <div><p class="font-semibold">Manage who can sign in to Triathlon Timing.</p><p class="mt-1 text-sm muted">Race access for Officials is normally added automatically when you assign them to a checkpoint.</p></div>
      <details class="w-full sm:w-auto">
        <summary class="btn-primary list-none"><i class="fa-solid fa-user-plus" aria-hidden="true"></i>Create account</summary>
        <form class="panel-pad mt-3 w-full sm:w-[420px]" @submit.prevent="submit">
          <h2 class="text-lg font-bold">Create account</h2>
          <div class="mt-4 space-y-3">
            <label class="label">Name<input v-model="form.name" class="field" required></label>
            <label class="label">Email<input v-model="form.email" type="email" class="field" required></label>
            <label class="label">Account type<select v-model="form.role" class="field"><option value="organizer">Official</option><option value="athlete">Athlete</option><option value="admin">Organizer (admin)</option></select></label>
            <AthletePicker v-if="form.role==='athlete'" v-model="form.athlete_id"/>
            <label class="label">Invitation<select v-model="form.delivery" class="field"><option value="email" :disabled="!mailConfigured">Email a password setup link</option><option value="manual">Use a temporary password</option></select></label>
            <label v-if="form.delivery==='manual'" class="label">Temporary password<input v-model="form.password" type="password" class="field" minlength="12" required></label>
            <details v-if="form.role==='organizer'" class="rounded-xl border border-outline p-3">
              <summary class="font-semibold">Advanced Official access</summary>
              <label class="label mt-3">Access preset<select v-model="form.access_role_id" class="field"><option :value="null">Official (default)</option><option v-for="role in accessRoles.filter(r=>!r.is_default)" :key="role.id" :value="role.id">{{ role.name }}</option></select></label>
              <p class="mt-2 text-xs muted">You normally do not need to choose races here. Assign this Official to a checkpoint from the Race workspace instead.</p>
              <div class="mt-3 max-h-40 space-y-2 overflow-auto rounded-xl bg-canvas p-3"><label v-for="race in races" :key="race.id" class="flex gap-2 text-sm"><input v-model="form.race_ids" type="checkbox" :value="race.id"><span>{{ race.name }}</span></label></div>
            </details>
          </div>
          <p v-if="Object.keys(form.errors).length" class="mt-3 text-sm text-error">{{ Object.values(form.errors)[0] }}</p>
          <button class="btn-primary mt-5 w-full" :disabled="form.processing"><i class="fa-solid fa-user-plus" aria-hidden="true"></i>Create account</button>
        </form>
      </details>
    </div>

    <section class="panel overflow-hidden">
      <div class="border-b border-outline p-4">
        <form @submit.prevent="searchUsers" class="flex flex-col gap-2 sm:flex-row sm:items-center">
          <input v-model="search" class="field" aria-label="Search people" placeholder="Search name or email">
          <button class="btn-secondary shrink-0"><i class="fa-solid fa-magnifying-glass" aria-hidden="true"></i>Search</button>
          <span class="sm:ml-auto whitespace-nowrap text-sm muted">{{ users.total }} accounts</span>
        </form>
      </div>
      <div class="divide-y divide-outline">
        <div v-for="user in users.data" :key="user.id" class="flex flex-col items-start gap-3 p-4 sm:flex-row sm:items-center">
          <div class="min-w-0 flex-1">
            <div class="flex flex-wrap items-center gap-2"><strong>{{ user.name }}</strong><span class="badge">{{ roleLabel(user) }}</span><span v-if="!user.is_active" class="badge !border-red-500/30 !text-error">Disabled</span></div>
            <div class="mt-1 text-sm muted">{{ user.email }}</div>
            <div class="mt-1 text-xs muted">
              <span v-if="user.athlete">Athlete: {{ user.athlete.first_name }} {{ user.athlete.last_name }}</span>
              <span v-if="user.athlete && user.races?.length"> · </span>
              <span v-if="user.races?.length">{{ user.races.length }} race{{ user.races.length===1?'':'s' }}</span>
              <span v-if="user.force_password_change"> · Setup pending</span>
            </div>
          </div>
          <Link :href="`/users/${user.id}/edit`" class="btn-secondary !px-3"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>Edit</Link>
        </div>
        <div v-if="!users.data.length" class="p-8 text-center muted">No accounts found.</div>
      </div>
      <nav aria-label="People pages" class="flex flex-wrap items-center justify-between gap-3 border-t border-outline p-4">
        <Link v-if="users.prev_page_url" :href="users.prev_page_url" class="btn-secondary" preserve-state><i class="fa-solid fa-chevron-left" aria-hidden="true"></i>Previous</Link><span v-else></span>
        <span class="text-sm muted">Page {{ users.current_page }} of {{ users.last_page }}</span>
        <Link v-if="users.next_page_url" :href="users.next_page_url" class="btn-secondary" preserve-state>Next<i class="fa-solid fa-chevron-right" aria-hidden="true"></i></Link><span v-else></span>
      </nav>
    </section>

    <details class="panel-pad mt-5">
      <summary class="font-bold"><i class="fa-solid fa-shield-halved mr-2" aria-hidden="true"></i>Official access presets</summary>
      <p class="mt-3 text-sm muted">Most Officials can use the default access. Custom presets are only needed when someone should also export results or have another special permission.</p>
      <div class="mt-4 grid gap-3 sm:grid-cols-2">
        <div v-for="role in accessRoles" :key="role.id" class="rounded-xl border border-outline p-3"><strong>{{ role.name }}</strong><span v-if="role.is_default" class="badge ml-2">Default</span><p class="mt-1 text-sm muted">{{ role.description || 'Official access preset' }}</p></div>
      </div>
      <Link href="/admin/roles" class="btn-secondary mt-4"><i class="fa-solid fa-sliders" aria-hidden="true"></i>Manage advanced access</Link>
    </details>
  </AppLayout>
</template>
