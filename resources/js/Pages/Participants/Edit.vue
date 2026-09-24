<script setup lang="ts">
import { tr } from '../../i18n';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
import type { Race } from '../../types';

interface Athlete {id:number;first_name:string;last_name:string;email:string|null;club:string|null;user?:{id:number;email:string;is_active:boolean;force_password_change:boolean}|null}
interface Snapshot {entry?:Record<string,unknown>;athletes?:Array<Record<string,unknown>>}
interface Change {id:number;reason:string;created_at:string;user?:{name:string};before:Snapshot;after:Snapshot}

const props=defineProps<{race:Race;entry:{id:number;bib_number:string|null;type:string;team_name:string|null;category:string|null;status:string;members:{athlete:Athlete}[]};changes:Change[];mailConfigured:boolean}>();
const form=useForm({bib_number:props.entry.bib_number??'',team_name:props.entry.team_name??'',category:props.entry.category??'',status:props.entry.status,reason:'',athletes:[...new Map(props.entry.members.map(m=>[m.athlete.id,{...m.athlete}])).values()]});
const save=()=>form.put(`/races/${props.race.id}/participants/${props.entry.id}`,{preserveScroll:true,onSuccess:()=>form.reset('reason')});

const value=(input:unknown)=>input===null||input===undefined||input===''?'—':String(input);
const entryLabels:Record<string,string>={bib_number:tr('Bib number'),team_name:tr('Team name'),category:tr('Category'),status:tr('Result status')};
const athleteLabels:Record<string,string>={first_name:tr('First name'),last_name:tr('Last name'),email:tr('Email'),club:tr('Club')};

function changeLines(change:Change):string[] {
  const lines:string[]=[];
  const beforeEntry=change.before?.entry??{};
  const afterEntry=change.after?.entry??{};
  for(const [key,label] of Object.entries(entryLabels)) {
    if(value(beforeEntry[key])!==value(afterEntry[key])) lines.push(`${label}: ${value(beforeEntry[key])} → ${value(afterEntry[key])}`);
  }
  const beforeAthletes=new Map((change.before?.athletes??[]).map(item=>[Number(item.id),item]));
  for(const after of change.after?.athletes??[]) {
    const id=Number(after.id);
    const before=beforeAthletes.get(id)??{};
    const athleteName=`${value(after.first_name)} ${value(after.last_name)}`.replace(/—/g,'').trim()||tr('Athlete');
    for(const [key,label] of Object.entries(athleteLabels)) {
      if(value(before[key])!==value(after[key])) lines.push(`${athleteName} · ${label}: ${value(before[key])} → ${value(after[key])}`);
    }
  }
  return lines.length?lines:[tr('No visible field changes recorded.')];
}
</script>

<template>
  <Head :title="$t('Edit participant')"/>
  <AppLayout :title="`${race.name} · Edit participant`">
    <form class="panel-pad max-w-3xl" @submit.prevent="save">
      <div v-if="race.started_at" class="mb-4 rounded-xl border border-outline bg-canvas p-4">
        <strong>{{ $t("Registration details are locked.") }}</strong>
        <p class="mt-1 text-sm muted">{{ $t("During a live or finished race, only the result status can be corrected here.") }}</p>
      </div>

      <div class="grid gap-4 sm:grid-cols-2">
        <template v-if="!race.started_at">
          <label class="label">{{ $t("Bib number") }} <span class="font-normal muted">{{ $t("(optional)") }}</span><input v-model="form.bib_number" class="field" maxlength="32"></label>
          <label class="label">{{ $t("Category") }} <span class="font-normal muted">{{ $t("(optional)") }}</span><input v-model="form.category" class="field"></label>
          <label v-if="entry.type==='relay'" class="label">{{ $t("Team name") }}<input v-model="form.team_name" class="field" required></label>
        </template>
        <label class="label">Result status<select v-model="form.status" class="field"><option value="registered">Competing / normal result</option><option value="dns">DNS — did not start</option><option value="dnf">DNF — did not finish</option><option value="dsq">DSQ — disqualified</option></select></label>
      </div>
      <p class="mt-3 text-sm muted">DNS, DNF and DSQ are excluded from places. Recorded timings remain available.</p>

      <template v-if="!race.started_at">
        <p class="mt-4 text-sm muted">Athlete profile corrections apply everywhere this athlete is reused.</p>
        <fieldset v-for="athlete in form.athletes" :key="athlete.id" class="mt-4 grid gap-3 rounded-xl border border-outline-strong p-4 sm:grid-cols-2">
          <legend class="px-1 font-semibold">Athlete details</legend>
          <label class="label">First name<input v-model="athlete.first_name" class="field" required></label>
          <label class="label">Last name<input v-model="athlete.last_name" class="field" required></label>
          <label class="label">Email <span class="font-normal muted">(optional)</span><input v-model="athlete.email" class="field" type="email"></label>
          <label class="label">Club <span class="font-normal muted">(optional)</span><input v-model="athlete.club" class="field"></label>
          <div class="sm:col-span-2 rounded-xl bg-canvas p-3">
            <template v-if="athlete.user">
              <strong class="text-sm">Results account linked</strong>
              <p class="mt-1 text-xs muted">{{ athlete.user.email }} · {{ athlete.user.is_active ? (athlete.user.force_password_change ? 'Invitation/password setup pending' : 'Active') : 'Disabled' }}</p>
              <button v-if="athlete.user.force_password_change && athlete.user.is_active && mailConfigured" type="button" class="btn-secondary mt-3" @click="router.post(`/athletes/${athlete.id}/account-invitation`,{}, {preserveScroll:true})"><i class="fa-solid fa-envelope" aria-hidden="true"></i>Resend invitation</button>
            </template>
            <template v-else-if="athlete.email && mailConfigured">
              <strong class="text-sm">Athlete access</strong>
              <p class="mt-1 text-xs muted">Invite this athlete to sign in and see all races linked to this profile.</p>
              <button type="button" class="btn-secondary mt-3" @click="router.post(`/athletes/${athlete.id}/account-invitation`,{}, {preserveScroll:true})"><i class="fa-solid fa-paper-plane" aria-hidden="true"></i>Invite to view results</button>
            </template>
            <p v-else-if="!athlete.email" class="text-xs muted">Add and save an email address before inviting this athlete to view results.</p>
            <p v-else class="text-xs muted">Email invitations are not configured. Manage the account from People instead.</p>
          </div>
        </fieldset>
      </template>

      <label class="label mt-4">Reason for change <span v-if="!race.started_at" class="font-normal muted">(optional)</span><textarea v-model="form.reason" class="field" :minlength="race.started_at?3:undefined" maxlength="1000" :required="!!race.started_at" :placeholder="race.started_at?'For example: withdrew during bike':'Optional note about this edit'"></textarea></label>
      <div v-if="Object.keys(form.errors).length" role="alert" class="mt-3 text-error"><p v-for="(error,key) in form.errors" :key="key">{{ error }}</p></div>
      <div class="mt-4 flex flex-wrap gap-3">
        <button class="btn-primary" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>{{ race.started_at?'Update result status':'Save participant' }}</button>
        <Link class="btn-secondary" :href="`/races/${race.id}/participants`"><i class="fa-solid fa-arrow-left" aria-hidden="true"></i>Back to participants</Link>
      </div>
    </form>

    <details class="panel-pad mt-5 max-w-3xl">
      <summary class="font-bold">Change history · {{ changes.length }}</summary>
      <p v-if="!changes.length" class="mt-3 muted">No edits yet.</p>
      <article v-for="change in changes" :key="change.id" class="border-b border-outline-strong py-4 last:border-0">
        <div class="flex flex-col gap-1 sm:flex-row sm:items-center sm:justify-between">
          <strong>{{ change.reason }}</strong>
          <span class="text-xs muted">{{ change.user?.name ?? 'Former user' }} · {{ new Date(change.created_at).toLocaleString() }}</span>
        </div>
        <ul class="mt-2 space-y-1 text-sm text-secondary">
          <li v-for="line in changeLines(change)" :key="line">{{ line }}</li>
        </ul>
      </article>
    </details>
  </AppLayout>
</template>
