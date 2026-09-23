<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { ref, nextTick } from 'vue';
import AppLayout from '../../Layouts/AppLayout.vue';
import ConfirmDialog from '../../Components/ConfirmDialog.vue';
interface Role { id:number; name:string; description:string|null; is_default:boolean; permissions:string[]; users_count:number }
const props=defineProps<{roles:Role[];permissions:Record<string,{label:string;description:string}>}>();
const selected=ref<Role|null>(null);
const editor=ref<HTMLFormElement>();
const removing=ref<Role|null>(null);
const deletion=useForm({});
const form=useForm({name:'',description:'',permissions:[] as string[]});
function edit(role:Role|null) {
 selected.value=role;form.clearErrors();form.name=role?.name??'';form.description=role?.description??'';form.permissions=[...(role?.permissions??[])];
 if(role)void nextTick(()=>{editor.value?.scrollIntoView({block:'start'});editor.value?.querySelector<HTMLInputElement>('input')?.focus({preventScroll:true});});
}
function save() {
 const options={preserveScroll:true,onSuccess:()=>edit(null)};
 if(selected.value) form.put(`/admin/roles/${selected.value.id}`,options); else form.post('/admin/roles',options);
}
function remove() {
 if(removing.value) deletion.delete(`/admin/roles/${removing.value.id}`,{preserveScroll:true,onSuccess:()=>{removing.value=null;edit(null);}});
}
</script>
<template>
 <Head title="Roles & permissions"/><AppLayout title="Roles & permissions">
  <p class="mb-4 muted">Create roles for your officials, choose what they can do, then assign a role and races from each user’s account. Assigned race overviews and results remain visible to every official.</p>
  <Link href="/users" class="btn-secondary mb-5">Assign roles in Users</Link>
  <div class="mb-5 grid gap-4 md:grid-cols-2"><section class="panel-pad"><h2 class="font-bold">Organizer (admin)</h2><p class="mt-2 muted">Full access to every race, user, role and permission. This built-in role cannot be changed or deleted. The last active admin is protected.</p></section><section class="panel-pad"><h2 class="font-bold">Athlete</h2><p class="mt-2 muted">View the linked athlete’s races and results. This built-in role cannot be changed or deleted.</p></section></div>
  <div class="grid gap-5 lg:grid-cols-2"><section class="panel-pad"><h2 class="text-lg font-bold">Official roles</h2>
   <article v-for="role in roles" :key="role.id" class="border-b border-outline py-4"><div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-bold">{{ role.name }} <span v-if="role.is_default" class="badge">Default</span></h3><div class="flex flex-wrap gap-2"><button class="btn-secondary" @click="edit(role)" :aria-label="`Edit ${role.name}`">Edit</button><button v-if="!role.is_default" class="btn-danger" :disabled="role.users_count>0" @click="deletion.clearErrors();removing=role" :aria-label="`Delete ${role.name}`">Delete</button></div></div><p class="mt-2 muted">{{ role.description }}</p><p class="mt-2 text-sm muted">{{ role.users_count }} assigned users · {{ role.permissions.length }} permissions</p><p v-if="!role.is_default && role.users_count" class="mt-1 text-xs muted">Reassign these users before deleting this role.</p><ul class="mt-2 list-disc pl-5 text-sm"><li v-for="key in role.permissions" :key="key">{{ permissions[key]?.label??key }}</li></ul></article>
  </section><form ref="editor" class="panel-pad h-fit scroll-mt-24 space-y-4" @submit.prevent="save"><h2 class="text-lg font-bold">{{ selected?'Edit role':'Create role' }}</h2><div><label for="role-name" class="label">Role name</label><input id="role-name" v-model="form.name" class="field" maxlength="100" required :readonly="selected?.is_default" placeholder="For example: Checkpoint official"></div><div><label for="role-description" class="label">Description (optional)</label><textarea id="role-description" v-model="form.description" class="field" maxlength="500"/></div><fieldset><legend class="label">Permissions</legend><p class="mb-3 text-sm muted">Select the actions this role can perform in assigned races. Clear a checkbox to remove that permission. Creating races also requires race setup.</p><label v-for="(permission,key) in permissions" :key="key" class="mb-3 flex items-start gap-3 rounded-xl border border-outline p-3"><input v-model="form.permissions" :value="key" type="checkbox" class="mt-1"><span><span class="font-semibold">{{ permission.label }}</span><span class="mt-1 block text-sm muted">{{ permission.description }}</span></span></label></fieldset><ul v-if="Object.keys(form.errors).length" role="alert" class="text-error"><li v-for="error in form.errors">{{ error }}</li></ul><div class="flex flex-wrap gap-3"><button class="btn-primary" :disabled="form.processing">{{ selected?'Save role':'Create role' }}</button><button v-if="selected" type="button" class="btn-secondary" @click="edit(null)">Cancel</button></div></form></div>
  <ConfirmDialog v-if="removing" title="Delete role?" :message="`Delete ${removing.name}? You can create a new role later.`" confirm-label="Delete role" :busy="deletion.processing" @cancel="removing=null" @confirm="remove"><p v-if="Object.keys(deletion.errors).length" role="alert" class="text-error">{{ Object.values(deletion.errors)[0] }}</p></ConfirmDialog>
 </AppLayout>
</template>
