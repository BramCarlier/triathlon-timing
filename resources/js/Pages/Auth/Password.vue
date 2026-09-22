<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';
const form = useForm({ current_password: '', password: '', password_confirmation: '' });
const submit = () => form.put('/account/password', { onSuccess: () => form.reset() });
</script>
<template>
  <Head title="Password" /><AppLayout title="Change password"><form class="panel-pad max-w-xl" @submit.prevent="submit">
    <p class="mb-5 muted">If you received a temporary password, enter it as your current password and choose a new password of at least 12 characters. You will then continue to your dashboard.</p><div class="space-y-4"><div><label class="label" for="change-current_password">Current password</label><input id="change-current_password" v-model="form.current_password" type="password" class="field"><p class="text-sm text-error">{{ form.errors.current_password }}</p></div><div><label class="label" for="change-password">New password</label><input id="change-password" v-model="form.password" type="password" class="field"><p class="text-sm text-error">{{ form.errors.password }}</p></div><div><label class="label" for="change-password_confirmation">Confirm new password</label><input id="change-password_confirmation" v-model="form.password_confirmation" type="password" class="field"></div></div><button class="btn-primary mt-6" :disabled="form.processing">Update password</button>
  </form></AppLayout>
</template>
