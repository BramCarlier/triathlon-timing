<script setup lang="ts">
import ThemeToggle from '../../Components/ThemeToggle.vue';
import { Head, useForm } from '@inertiajs/vue3';
const form = useForm({ email: '', password: '', remember: true });
const submit = () => form.post('/login');
</script>
<template><div class="auth-theme fixed right-4 top-4 z-30"><ThemeToggle /></div>
  <Head title="Log in" />
  <div class="auth-screen grid min-h-screen place-items-center app-background px-4">
    <form class="panel-pad w-full max-w-md" @submit.prevent="submit">
      <div class="mb-8 text-center"><div class="mx-auto mb-4 grid size-14 place-items-center rounded-2xl bg-cyan-400 text-2xl font-black text-slate-950">T</div><h1 class="text-2xl font-bold">Triathlon Timing</h1><p class="mt-2 muted">Race control, checkpoint timing and athlete results.</p></div>
      <label class="label" for="email">Email</label><input id="email" v-model="form.email" class="field mb-1" type="email" autocomplete="email" required><p v-if="form.errors.email" class="mb-4 text-sm text-error">{{ form.errors.email }}</p>
      <label class="label mt-4" for="password">Password</label><input id="password" v-model="form.password" class="field" type="password" autocomplete="current-password" required><p v-if="form.errors.password" class="mt-1 text-sm text-error">{{ form.errors.password }}</p>
      <label class="mt-4 flex items-center gap-2 text-sm text-secondary"><input v-model="form.remember" type="checkbox" class="size-4"> Keep me signed in on this device</label>
      <button class="btn-primary mt-6 w-full" :disabled="form.processing">{{ form.processing ? 'Signing in…' : 'Log in' }}</button>
      <a href="/forgot-password" class="mt-4 block text-center text-sm text-accent hover:text-accent">Forgot password?</a>
    </form>
  </div>
</template>
