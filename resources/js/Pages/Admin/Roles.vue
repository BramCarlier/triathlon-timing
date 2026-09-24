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
 <Head :title="$t('Official access')"/><AppLayout :title="$t('Official access')">
  <p class="mb-4 muted">{{ $t("Most Officials can use the default access. Create a custom preset only when someone needs an extra permission such as results export.") }}</p>
  <Link href="/users" class="btn-secondary mb-5"><i class="fa-solid fa-users" aria-hidden="true"></i>{{ $t("Back to People & access") }}</Link>
  <div class="mb-5 grid gap-4 md:grid-cols-2"><section class="panel-pad"><h2 class="font-bold">{{ $t("Organizer (admin)") }}</h2><p class="mt-2 muted">{{ $t("Full access to every race, user, role and permission. This built-in role cannot be changed or deleted. The last active admin is protected.") }}</p></section><section class="panel-pad"><h2 class="font-bold">{{ $t("Athlete") }}</h2><p class="mt-2 muted">{{ $t("View the linked athlete’s races and results. This built-in role cannot be changed or deleted.") }}</p></section></div>
  <div class="grid gap-5 lg:grid-cols-2"><section class="panel-pad"><h2 class="text-lg font-bold">{{ $t("Access presets") }}</h2>
   <article v-for="role in roles" :key="role.id" class="border-b border-outline py-4"><div class="flex flex-wrap items-center justify-between gap-3"><h3 class="font-bold">{{ role.name }} <span v-if="role.is_default" class="badge">{{ $t("Default") }}</span></h3><div class="flex flex-wrap gap-2"><button class="btn-secondary" @click="edit(role)" :aria-label="`Edit ${role.name}`"><i class="fa-solid fa-pen-to-square" aria-hidden="true"></i>{{ $t("Edit") }}</button><button v-if="!role.is_default" class="btn-danger" :disabled="role.users_count>0" @click="deletion.clearErrors();removing=role" :aria-label="`Delete ${role.name}`"><i class="fa-solid fa-trash" aria-hidden="true"></i>{{ $t("Delete") }}</button></div></div><p class="mt-2 muted">{{ role.description }}</p><p class="mt-2 text-sm muted">{{ role.users_count }} assigned users · {{ role.permissions.length }} permissions</p><p v-if="!role.is_default && role.users_count" class="mt-1 text-xs muted">{{ $t("Reassign these users before deleting this role.") }}</p><ul class="mt-2 list-disc pl-5 text-sm"><li v-for="key in role.permissions" :key="key">{{ permissions[key]?.label??key }}</li></ul></article>
  </section><form ref="editor" class="panel-pad h-fit scroll-mt-24 space-y-4" @submit.prevent="save"><h2 class="text-lg font-bold">{{ selected?$t('Edit preset'):$t('Create preset') }}</h2><div><label for="role-name" class="label">{{ $t("Preset name") }}</label><input id="role-name" v-model="form.name" class="field" maxlength="100" required :readonly="selected?.is_default" :placeholder="$t('For example: Checkpoint official')"></div><div><label for="role-description" class="label">{{ $t("Description (optional)") }}</label><textarea id="role-description" v-model="form.description" class="field" maxlength="500"/></div><fieldset><legend class="label">{{ $t("Permissions") }}</legend><p class="mb-3 text-sm muted">{{ $t("Select the extra actions this Official role can perform. Checkpoint location is assigned separately from the Race workspace.") }}</p><label v-for="(permission,key) in permissions" :key="key" class="mb-3 flex items-start gap-3 rounded-xl border border-outline p-3"><input v-model="form.permissions" :value="key" type="checkbox" class="mt-1"><span><span class="font-semibold">{{ permission.label }}</span><span class="mt-1 block text-sm muted">{{ permission.description }}</span></span></label></fieldset><ul v-if="Object.keys(form.errors).length" role="alert" class="text-error"><li v-for="error in form.errors">{{ error }}</li></ul><div class="flex flex-wrap gap-3"><button class="btn-primary" :disabled="form.processing"><i :class="selected?'fa-solid fa-floppy-disk':'fa-solid fa-plus'" aria-hidden="true"></i>{{ selected?$t('Save preset'):$t('Create preset') }}</button><button v-if="selected" type="button" class="btn-secondary" @click="edit(null)"><i class="fa-solid fa-xmark" aria-hidden="true"></i>{{ $t("Cancel") }}</button></div></form></div>
  <ConfirmDialog v-if="removing" :title="$t('Delete preset?')" :message="`Delete ${removing.name}? You can create a new role later.`" confirm-label="Delete preset" :busy="deletion.processing" @cancel="removing=null" @confirm="remove"><p v-if="Object.keys(deletion.errors).length" role="alert" class="text-error">{{ Object.values(deletion.errors)[0] }}</p></ConfirmDialog>
 </AppLayout>
</template>
