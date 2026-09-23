<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Race } from '../../types';
interface Athlete {id:number;first_name:string;last_name:string;email:string|null;club:string|null}
const props=defineProps<{race:Race;entry:{id:number;bib_number:string|null;type:string;team_name:string|null;category:string|null;status:string;members:{athlete:Athlete}[]};changes:{id:number;reason:string;created_at:string;user?:{name:string};before:unknown;after:unknown}[]}>();
const form=useForm({bib_number:props.entry.bib_number??'',team_name:props.entry.team_name??'',category:props.entry.category??'',status:props.entry.status,reason:'',athletes:[...new Map(props.entry.members.map(m=>[m.athlete.id,{...m.athlete}])).values()]});
const save=()=>form.put(`/races/${props.race.id}/participants/${props.entry.id}`,{preserveScroll:true,onSuccess:()=>form.reset('reason')});
</script>
<template><Head title="Edit participant"/><AppLayout :title="`${race.name} · Edit participant`">
<form class="panel-pad max-w-3xl" @submit.prevent="save">
<div class="grid gap-4 sm:grid-cols-2">
<label class="label">Bib number (optional)<input v-model="form.bib_number" class="field" maxlength="32"></label>
<label class="label">Category<input v-model="form.category" class="field"></label>
<label v-if="entry.type==='relay'" class="label">Team name<input v-model="form.team_name" class="field" required></label>
<label class="label">Result status<select v-model="form.status" class="field"><option value="registered">Competing / normal result</option><option value="dns">DNS — did not start</option><option value="dnf">DNF — did not finish</option><option value="dsq">DSQ — disqualified</option></select></label>
</div><p class="muted mt-3">DNS, DNF and DSQ entries are excluded from places. Recorded times remain available. Returning to competing restores the normal result.</p>
<p class="muted mt-3">Athlete profile corrections apply to all races using that athlete. Relay membership and entry type stay unchanged.</p>
<fieldset v-for="athlete in form.athletes" :key="athlete.id" class="mt-4 grid gap-3 rounded-xl border border-outline-strong p-4 sm:grid-cols-2"><legend>Athlete profile</legend>
<label class="label">First name<input v-model="athlete.first_name" class="field" required></label><label class="label">Last name<input v-model="athlete.last_name" class="field" required></label>
<label class="label">Email<input v-model="athlete.email" class="field" type="email"></label><label class="label">Club<input v-model="athlete.club" class="field"></label></fieldset>
<label class="label mt-4">Reason for change<textarea v-model="form.reason" class="field" minlength="3" maxlength="1000" required placeholder="For example: bib assigned at registration"></textarea></label>
<div v-if="Object.keys(form.errors).length" role="alert" class="mt-3 text-error"><p v-for="(error,key) in form.errors" :key="key">{{ error }}</p></div>
<div class="mt-4 flex flex-wrap gap-3"><button class="btn-primary" :disabled="form.processing">Save participant</button><Link class="btn-secondary" :href="`/races/${race.id}/participants`">Back to participants</Link></div>
</form><section class="panel-pad mt-5 max-w-3xl"><h2 class="text-xl font-bold">Change history</h2><p v-if="!changes.length" class="muted">No edits yet.</p><details v-for="change in changes" :key="change.id" class="border-b border-outline-strong py-3"><summary>{{ change.reason }} · {{ change.user?.name ?? 'Former user' }} · {{ new Date(change.created_at).toLocaleString() }}</summary><div class="mt-2 grid gap-3 sm:grid-cols-2"><div><strong>Before</strong><pre class="overflow-auto text-xs">{{ JSON.stringify(change.before,null,2) }}</pre></div><div><strong>After</strong><pre class="overflow-auto text-xs">{{ JSON.stringify(change.after,null,2) }}</pre></div></div></details></section>
</AppLayout></template>
