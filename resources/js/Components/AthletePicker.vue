<script setup lang="ts">
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';
import { jsonRequest } from '../lib';

interface Athlete {id:number;first_name:string;last_name:string;email?:string|null}
const props=defineProps<{accountId?:number;initial?:Athlete|null}>();
const model=defineModel<number|null>({required:true});
const query=ref('');
const rows=ref<Athlete[]>([]);
const selected=ref(props.initial??null);
const page=ref(1);
const last=ref(1);
const busy=ref(false);
const error=ref('');
let timer:number|undefined;
let request=0;

async function load(number=1){
  const current=++request;
  busy.value=true;
  error.value='';
  try{
    const params=new URLSearchParams({q:query.value,page:String(number)});
    if(props.accountId)params.set('account_id',String(props.accountId));
    const {response,data}=await jsonRequest<{data:Athlete[];current_page:number;last_page:number}>(`/users/athletes?${params}`);
    if(current!==request)return;
    if(!response.ok)throw new Error();
    rows.value=data.data;
    page.value=data.current_page;
    last.value=data.last_page;
  }catch{
    if(current===request){rows.value=[];error.value='Could not load athletes. Please try again.';}
  }finally{if(current===request)busy.value=false;}
}
function choose(athlete:Athlete){selected.value=athlete;model.value=athlete.id;}
function clear(){selected.value=null;model.value=null;}
watch(query,()=>{clearTimeout(timer);timer=window.setTimeout(()=>load(1),220);});
onMounted(()=>load());
onBeforeUnmount(()=>clearTimeout(timer));
</script>

<template>
  <div class="space-y-3">
    <div v-if="selected && model===selected.id" class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 p-3">
      <div class="flex items-start justify-between gap-3"><div><span class="text-xs font-bold uppercase tracking-wider text-success">Athlete selected</span><strong class="mt-1 block">{{ selected.first_name }} {{ selected.last_name }}</strong><span class="text-sm muted">{{ selected.email || 'No email' }}</span></div><button type="button" class="btn-secondary !px-3" @click="clear">Change</button></div>
    </div>
    <template v-else>
      <label for="athlete-search" class="label">Athlete</label>
      <input id="athlete-search" v-model="query" maxlength="100" class="field" placeholder="Filter by name or email" autocomplete="off">
      <p class="text-xs muted">Available athlete profiles are shown below and filtered as you type.</p>
      <p v-if="busy" class="text-sm muted"><i class="fa-solid fa-spinner fa-spin mr-1" aria-hidden="true"></i>Loading athletes…</p>
      <p v-if="error" role="alert" class="text-error">{{ error }}</p>
      <div v-if="!busy && rows.length" class="max-h-64 space-y-1 overflow-auto rounded-xl border border-outline bg-canvas p-2">
        <button v-for="athlete in rows" :key="athlete.id" type="button" class="w-full rounded-xl border border-outline p-3 text-left hover:border-cyan-400 hover:bg-raised" @click="choose(athlete)">
          <strong>{{ athlete.first_name }} {{ athlete.last_name }}</strong><span class="mt-1 block text-xs muted">{{ athlete.email || 'No email' }}</span>
        </button>
      </div>
      <p v-else-if="!busy&&!error" class="rounded-xl bg-canvas p-3 text-sm muted">No available athlete matches.</p>
      <div v-if="last>1" class="flex items-center justify-between gap-2"><button type="button" class="btn-secondary" :disabled="busy||page<=1" @click="load(page-1)">Previous</button><span class="text-xs muted">{{ page }} / {{ last }}</span><button type="button" class="btn-secondary" :disabled="busy||page>=last" @click="load(page+1)">Next</button></div>
    </template>
  </div>
</template>
