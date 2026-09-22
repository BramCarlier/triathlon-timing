<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch, onBeforeUnmount } from 'vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { bibLabel } from '../../lib';
import type { Race } from '../../types';
interface Athlete { id:number; full_name:string; first_name:string; last_name:string; email?:string; club?:string }
interface Member { id:number; discipline:string; athlete:Athlete }
interface Entry { id:number; bib_number:string|null; type:'solo'|'relay'; team_name?:string; category?:string; members:Member[] }
interface Paginator { data:Entry[]; current_page:number; last_page:number; prev_page_url?:string|null; next_page_url?:string|null; total:number }
const props = defineProps<{ race:Race; entries:Paginator; search:string }>();
const searchValue = ref(props.search);
let searchTimer:number|undefined;
watch(searchValue, value => { clearTimeout(searchTimer); searchTimer = window.setTimeout(() => router.get(`/races/${props.race.id}/participants`, value ? {search:value} : {}, {preserveState:true, replace:true}), 250); });
const form = useForm({ bib_number:'', type:'solo' as 'solo'|'relay', team_name:'', category:'', members:[{discipline:'swim',first_name:'',last_name:'',email:'',club:''}] });
watch(() => form.type, type => { form.members = type === 'solo' ? [{discipline:'swim',first_name:'',last_name:'',email:'',club:''}] : ['swim','bike','run'].map(discipline => ({discipline,first_name:'',last_name:'',email:'',club:''})); });
const submit = () => form.post(`/races/${props.race.id}/participants`, { preserveScroll:true, onSuccess:()=>form.reset() });
onBeforeUnmount(() => clearTimeout(searchTimer));
const displayName = (entry:Entry) => entry.type === 'relay' ? entry.team_name : entry.members[0]?.athlete.full_name;
const removing=ref<Entry|null>(null);
const removeError=ref('');
const remove=(entry:Entry)=>{removing.value=entry;};
const confirmRemove=()=>{if(removing.value)router.delete(`/races/${props.race.id}/participants/${removing.value.id}`,{preserveScroll:true,onSuccess:()=>{removing.value=null;removeError.value='';},onError:(errors)=>{removeError.value=Object.values(errors)[0];removing.value=null;}});};
const disciplineLabel = (d:string) => d.charAt(0).toUpperCase()+d.slice(1);
</script>
<template><Head :title="`${race.name} participants`"/><AppLayout :title="`${race.name} · Participants`">
  <div class="mb-5 flex flex-wrap gap-2"><Link :href="`/races/${race.id}/participants/import`" class="btn-primary">Import file</Link></div>
  <p v-if="removeError" role="alert" class="mb-4 text-red-300">{{ removeError }}</p>
  <div class="grid gap-5 xl:grid-cols-[1fr_420px]">
    <section class="panel overflow-hidden"><div class="border-b border-slate-800 p-4"><div class="flex items-center gap-3"><input v-model="searchValue" class="field" placeholder="Search bib, athlete or team…"><span class="whitespace-nowrap muted text-sm">{{ entries.total }} entries</span></div></div><div class="divide-y divide-slate-800"><div v-for="entry in entries.data" :key="entry.id" class="flex items-start gap-4 p-4"><div class="rounded-xl bg-slate-800 px-3 py-2 font-mono text-lg font-bold">{{ bibLabel(entry.bib_number) }}</div><div class="min-w-0 flex-1"><div class="font-semibold">{{ displayName(entry) }}</div><div class="muted text-sm">{{ entry.type === 'relay' ? 'Trio relay' : 'Solo' }}<span v-if="entry.category"> · {{ entry.category }}</span></div><div v-if="entry.type === 'relay'" class="mt-1 flex flex-wrap gap-x-3 text-xs text-slate-400"><span v-for="member in entry.members" :key="member.id">{{ disciplineLabel(member.discipline) }}: {{ member.athlete.full_name }}</span></div></div><Link class="btn-secondary" :href="`/races/${race.id}/participants/${entry.id}/edit`">Edit</Link><button class="rounded-lg px-2 py-1 text-sm text-red-300 hover:bg-red-500/10" @click="remove(entry)">Remove</button></div><div v-if="!entries.data.length" class="p-8 text-center muted">No participants found.</div></div><div class="flex items-center justify-between border-t border-slate-800 p-4"><Link v-if="entries.prev_page_url" :href="entries.prev_page_url" class="btn-secondary">Previous</Link><span v-else></span><span class="muted text-sm">Page {{ entries.current_page }} of {{ entries.last_page }}</span><Link v-if="entries.next_page_url" :href="entries.next_page_url" class="btn-secondary">Next</Link><span v-else></span></div></section>
    <form class="panel-pad h-fit" @submit.prevent="submit"><h2 class="mb-4 text-lg font-bold">Add manually</h2><div class="grid grid-cols-2 gap-3"><div><label for="participant-bib" class="label">Bib number (optional)</label><input id="participant-bib" v-model="form.bib_number" class="field" maxlength="32" placeholder="Leave blank if not assigned"></div><div><label class="label">Type</label><select v-model="form.type" class="field"><option value="solo">Solo</option><option value="relay">Trio relay</option></select></div><div v-if="form.type==='relay'" class="col-span-2"><label class="label">Team name</label><input v-model="form.team_name" class="field" required></div><div class="col-span-2"><label class="label">Category</label><input v-model="form.category" class="field" placeholder="Optional"></div></div><div class="mt-5 space-y-4"><div v-for="(member,index) in form.members" :key="member.discipline" class="rounded-xl border border-slate-800 p-3"><div class="mb-3 font-semibold">{{ form.type==='solo' ? 'Athlete' : disciplineLabel(member.discipline) }}</div><div class="grid grid-cols-2 gap-2"><input v-model="member.first_name" class="field" placeholder="First name" required><input v-model="member.last_name" class="field" placeholder="Last name" required><input v-model="member.email" class="field col-span-2" type="email" placeholder="Email (optional)"><input v-model="member.club" class="field col-span-2" placeholder="Club (optional)"></div></div></div><p v-if="Object.keys(form.errors).length" class="mt-3 text-sm text-red-300">{{ Object.values(form.errors)[0] }}</p><button class="btn-primary mt-5 w-full" :disabled="form.processing">Add participant</button></form>
  </div>
<ConfirmDialog v-if="removing" title="Remove participant?" :message="`Remove ${displayName(removing)}? Participants with timing history cannot be removed.`" confirm-label="Remove participant" @cancel="removing=null" @confirm="confirmRemove"/>
</AppLayout></template>
