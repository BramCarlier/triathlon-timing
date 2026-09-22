import { computed, ref } from 'vue';
import { jsonRequest } from '../lib';
import { mayReplay, retryDisposition } from '../queuePolicy';
export interface QueuedTiming {
  client_uuid:string; url:string; payload:Record<string,unknown>; queued_at:string;
  operator_id?:number; label?:string; elapsed_ms?:number; error?:string; blocked?:boolean; warning?:boolean;
}
const STORE='timings';
async function db():Promise<IDBDatabase> {
  return new Promise((resolve,reject)=>{
    const request=indexedDB.open('triathlon-timing',1);
    request.onupgradeneeded=()=>{if(!request.result.objectStoreNames.contains(STORE)) request.result.createObjectStore(STORE,{keyPath:'client_uuid'});};
    request.onsuccess=()=>resolve(request.result); request.onerror=()=>reject(request.error);
  });
}
async function transaction<T>(mode:IDBTransactionMode, action:(store:IDBObjectStore)=>IDBRequest<T>):Promise<T> {
  const database=await db();
  return new Promise((resolve,reject)=>{
    const tx=database.transaction(STORE,mode); const request=action(tx.objectStore(STORE));
    tx.oncomplete=()=>{database.close();resolve(request.result);};
    tx.onabort=tx.onerror=()=>{database.close();reject(tx.error??request.error);};
  });
}
const all=()=>transaction<QueuedTiming[]>('readonly',s=>s.getAll());
const put=(item:QueuedTiming)=>transaction('readwrite',s=>s.put(item));
const remove=(id:string)=>transaction('readwrite',s=>s.delete(id));
export function useOfflineTimingQueue(raceId:number,operatorId:number) {
  const items=ref<QueuedTiming[]>([]); const foreignCount=ref(0); const legacy=ref<QueuedTiming[]>([]);
  const error=ref(''); const flushing=ref(false); const pending=computed(()=>items.value.length);
  const scoped=async()=> (await all()).filter(i=>i.url===`/races/${raceId}/timings`);
  const refresh=async()=>{
    try {const rows=await scoped();items.value=rows.filter(i=>mayReplay(i.operator_id,operatorId)).sort((a,b)=>a.queued_at.localeCompare(b.queued_at));foreignCount.value=rows.filter(i=>i.operator_id!==undefined&&!mayReplay(i.operator_id,operatorId)).length;legacy.value=rows.filter(i=>i.operator_id===undefined);error.value='';}
    catch {error.value='Device storage is unavailable. Keep this page open; offline timing cannot be saved safely.';throw new Error(error.value);}
  };
  const queue=async(url:string,payload:Record<string,unknown>,label:string,elapsed_ms:number)=>{
    await put({client_uuid:String(payload.client_uuid),url,payload:{...payload,source:'offline'},operator_id:operatorId,label,elapsed_ms,queued_at:new Date().toISOString()});await refresh();
  };
  const flush=async()=>{
    if(!navigator.onLine||flushing.value)return false;
    flushing.value=true;let changed=false;
    try {
      const run=async()=>{
        for(const item of await scoped()) {
          if(!mayReplay(item.operator_id,operatorId)||item.blocked)continue;
          try {
            const {response,data}=await jsonRequest<{message?:string;warning?:boolean;timing?:{client_uuid:string}}>(item.url,{method:'POST',body:JSON.stringify(item.payload),signal:AbortSignal.timeout(12000)});
            if(response.ok&&data.timing?.client_uuid===item.client_uuid){await remove(item.client_uuid);changed=true;}
            else {await put({...item,error:data.message??`Server response ${response.status}`,blocked:retryDisposition(response.status)==='review',warning:!!data.warning});if([401,403,419,429].includes(response.status)||response.status>=500)break;}
          } catch {await put({...item,error:'Connection interrupted. Will retry automatically.'});break;}
        }
      };
      if(navigator.locks) await navigator.locks.request(`timing-sync-${raceId}-${operatorId}`,run);else await run();
      await refresh();return changed;
    } finally {flushing.value=false;}
  };
  const retry=async(id:string,override=false)=>{
    const item=(await scoped()).find(i=>i.client_uuid===id&&mayReplay(i.operator_id,operatorId));
    if(item)await put({...item,blocked:false,error:undefined,payload:{...item.payload,override_warning:override||item.payload.override_warning}});
    return flush();
  };
  const discard=async(id:string)=>{const item=(await scoped()).find(i=>i.client_uuid===id&&mayReplay(i.operator_id,operatorId));if(item)await remove(id);await refresh();};
  return {items,pending,foreignCount,legacy,error,flushing,queue,flush,refresh,retry,discard};
}
