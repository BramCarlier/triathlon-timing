<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
const form = useForm({ current_password: '', password: '', password_confirmation: '' });
const submit = () => form.put('/account/password', { onSuccess: () => form.reset() });
</script>
<template>
  <Head title="Password" /><AppLayout title="Change password"><form class="panel-pad max-w-xl" @submit.prevent="submit">
    <div class="space-y-4"><div><label class="label">Current password</label><input v-model="form.current_password" type="password" class="field"><p class="text-sm text-red-300">{{ form.errors.current_password }}</p></div><div><label class="label">New password</label><input v-model="form.password" type="password" class="field"><p class="text-sm text-red-300">{{ form.errors.password }}</p></div><div><label class="label">Confirm new password</label><input v-model="form.password_confirmation" type="password" class="field"></div></div><button class="btn-primary mt-6" :disabled="form.processing">Update password</button>
  </form></AppLayout>
</template>
