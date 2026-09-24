<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { ref, watch, onBeforeUnmount } from 'vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
import RaceAthletePicker from '../../Components/RaceAthletePicker.vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import { bibLabel } from '../../lib';
import type { Race } from '../../types';
interface Athlete { id:number; full_name:string; first_name:string; last_name:string; email?:string; club?:string }
interface AthleteChoice { id:number; full_name:string; first_name:string; last_name:string; email:string|null; club:string|null; race_count:number; already_in_race:boolean; races:Array<{id:number;name:string;event_date:string}>; recent_bibs:string[] }
interface Member { id:number; discipline:string; athlete:Athlete }
interface Entry { id:number; bib_number:string|null; type:'solo'|'relay'; team_name?:string; category?:string; members:Member[] }
interface Paginator { data:Entry[]; current_page:number; last_page:number; prev_page_url?:string|null; next_page_url?:string|null; total:number }
const props = defineProps<{ race:Race; entries:Paginator; search:string; athleteOptions:AthleteChoice[] }>();
const searchValue = ref(props.search);
let searchTimer:number|undefined;
watch(searchValue, value => { clearTimeout(searchTimer); searchTimer = window.setTimeout(() => router.get(`/races/${props.race.id}/participants`, value ? {search:value} : {}, {preserveState:true, replace:true}), 250); });
const form = useForm({ bib_number:'', type:'solo' as 'solo'|'relay', team_name:'', category:'', members:[{discipline:'swim',athlete_id:null as number|null,first_name:'',last_name:'',email:'',club:''}] });
watch(() => form.type, type => { form.members = type === 'solo' ? [{discipline:'swim',athlete_id:null,first_name:'',last_name:'',email:'',club:''}] : ['swim','bike','run'].map(discipline => ({discipline,athlete_id:null as number|null,first_name:'',last_name:'',email:'',club:''})); });
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
  <div v-if="!race.started_at" class="mb-5 flex flex-wrap gap-2"><Link :href="`/races/${race.id}/participants/import`" class="btn-primary"><i class="fa-solid fa-file-import" aria-hidden="true"></i>Import file</Link></div>
  <div v-else class="mb-5 rounded-xl border border-outline bg-canvas p-4"><strong>Registration is locked.</strong><p class="mt-1 text-sm muted">The race has started, so participants can no longer be added, removed or have registration details changed.</p></div>
  <p v-if="removeError" role="alert" class="mb-4 text-error">{{ removeError }}</p>
  <div class="grid gap-5" :class="!race.started_at?'xl:grid-cols-[1fr_420px]':''">
    <section class="panel overflow-hidden"><div class="border-b border-outline p-4"><div class="flex items-center gap-3"><input aria-label="Search participants" v-model="searchValue" class="field" placeholder="Search bib, athlete or team…"><span class="whitespace-nowrap muted text-sm">{{ entries.total }} participants</span></div></div><div class="divide-y divide-outline"><div v-for="entry in entries.data" :key="entry.id" class="grid grid-cols-[auto_minmax(0,1fr)] items-start gap-3 p-4 sm:grid-cols-[auto_minmax(0,1fr)_auto_auto]"><div class="max-w-24 rounded-xl bg-raised px-3 py-2 font-mono text-lg font-bold">{{ bibLabel(entry.bib_number) }}</div><div class="min-w-0 flex-1"><div class="font-semibold">{{ displayName(entry) }}</div><div class="muted text-sm">{{ entry.type === 'relay' ? 'Trio relay' : 'Solo' }}<span v-if="entry.category"> · {{ entry.category }}</span></div><div v-if="entry.type === 'relay'" class="mt-1 flex flex-wrap gap-x-3 text-xs text-muted"><span v-for="member in entry.members" :key="member.id">{{ disciplineLabel(member.discipline) }}: {{ member.athlete.full_name }}</span></div></div><Link class="btn-secondary" :href="`/races/${race.id}/participants/${entry.id}/edit`"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>{{ race.started_at?'Result status':'Edit' }}</Link><button v-if="!race.started_at" class="inline-flex min-h-11 items-center gap-2 rounded-lg px-3 py-2 text-sm text-error hover:bg-red-500/10" @click="remove(entry)"><i class="fa-solid fa-trash" aria-hidden="true"></i>Remove</button></div><div v-if="!entries.data.length" class="p-8 text-center muted">No participants found.</div></div><div class="flex flex-wrap items-center justify-between gap-2 border-t border-outline p-4"><Link v-if="entries.prev_page_url" :href="entries.prev_page_url" class="btn-secondary"><i class="fa-solid fa-chevron-left" aria-hidden="true"></i>Previous</Link><span v-else></span><span class="muted text-sm">Page {{ entries.current_page }} of {{ entries.last_page }}</span><Link v-if="entries.next_page_url" :href="entries.next_page_url" class="btn-secondary">Next<i class="fa-solid fa-chevron-right" aria-hidden="true"></i></Link><span v-else></span></div></section>
    <form v-if="!race.started_at" class="panel-pad h-fit" @submit.prevent="submit">
      <div>
        <h2 class="text-lg font-bold">Add athlete</h2>
        <p class="mt-1 text-sm muted">For a normal solo entry, a name is enough. Everything else is optional.</p>
      </div>

      <div class="mt-4">
        <label for="participant-bib" class="label">Bib number <span class="font-normal muted">(optional)</span></label>
        <input id="participant-bib" v-model="form.bib_number" class="field" maxlength="32" placeholder="Leave blank if you do not use bibs">
      </div>

      <details class="mt-4 rounded-xl border border-outline p-3">
        <summary class="cursor-pointer text-sm font-semibold">Relay, category & other race options</summary>
        <div class="mt-3 grid gap-3">
          <label for="participants-index-field-1" class="label">Entry type
            <select id="participants-index-field-1" v-model="form.type" class="field">
              <option value="solo">Solo athlete</option>
              <option value="relay">3-person relay</option>
            </select>
          </label>
          <label v-if="form.type==='relay'" for="participants-index-field-2" class="label">Team name
            <input id="participants-index-field-2" v-model="form.team_name" class="field" required>
          </label>
          <label for="participants-index-field-3" class="label">Category <span class="font-normal muted">(optional)</span>
            <input id="participants-index-field-3" v-model="form.category" class="field" placeholder="For example: Open">
          </label>
        </div>
      </details>

      <div class="mt-4 space-y-4">
        <RaceAthletePicker
          v-for="(member,index) in form.members"
          :key="member.discipline"
          v-model="form.members[index]"
          :race-id="race.id"
          :bib-number="form.bib_number"
          :initial-options="athleteOptions"
          :title="form.type==='solo' ? 'Athlete name' : disciplineLabel(member.discipline)"
        />
      </div>

      <p v-if="Object.keys(form.errors).length" class="mt-3 text-sm text-error">{{ Object.values(form.errors)[0] }}</p>
      <button class="btn-primary mt-5 w-full" :disabled="form.processing"><i class="fa-solid fa-plus" aria-hidden="true"></i>Add athlete</button>
    </form>
  </div>
<ConfirmDialog v-if="removing" title="Remove participant?" :message="`Remove ${displayName(removing)}? Participants with timing history cannot be removed.`" confirm-label="Remove participant" @cancel="removing=null" @confirm="confirmRemove"/>
</AppLayout></template>
