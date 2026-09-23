<script setup lang="ts">
import ThemeToggle from '../../Components/ThemeToggle.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ token: string; email: string; welcome?:boolean }>();
const form = useForm({ token: props.token, email: props.email, password: '', password_confirmation: '' });
const submit = () => form.post('/reset-password');
</script>

<template><div class="auth-theme fixed right-4 top-4 z-30"><ThemeToggle /></div>
  <Head title="Reset password" />
  <main class="auth-screen mx-auto flex min-h-screen max-w-md items-center px-4 py-20">
    <form class="panel-pad w-full" @submit.prevent="submit">
      <div class="mb-6">
        <div class="text-xs font-bold uppercase tracking-[.2em] text-accent">Triathlon Timing</div>
        <h1 class="mt-2 text-2xl font-black">{{ welcome?'Welcome — set your password':'Choose a new password' }}</h1><p class="mt-3 muted">Use at least 12 characters. After saving, sign in with your email and new password.</p>
      </div>
      <div class="space-y-4">
        <div><label class="label" for="reset-email">Email</label><input id="reset-email" v-model="form.email" class="field" type="email" autocomplete="email" required></div>
        <div><label class="label" for="reset-password">New password</label><input id="reset-password" v-model="form.password" class="field" type="password" minlength="12" autocomplete="new-password" required></div>
        <div><label class="label" for="reset-password_confirmation">Confirm password</label><input id="reset-password_confirmation" v-model="form.password_confirmation" class="field" type="password" minlength="12" autocomplete="new-password" required></div>
      </div>
      <p v-if="Object.keys(form.errors).length" class="mt-3 text-sm text-error">{{ Object.values(form.errors)[0] }}</p>
      <button class="btn-primary mt-5 w-full" :disabled="form.processing"><i class="fa-solid fa-floppy-disk" aria-hidden="true"></i>Save password</button>
    </form>
  </main>
</template>
