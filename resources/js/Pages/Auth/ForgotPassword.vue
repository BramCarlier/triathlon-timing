<script setup lang="ts">
import ThemeToggle from '../../Components/ThemeToggle.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '' });
const submit = () => form.post('/forgot-password');
</script>

<template><div class="auth-theme fixed right-4 top-4 z-30"><ThemeToggle /></div>
  <Head title="Forgot password" />
  <main class="auth-screen mx-auto flex min-h-screen max-w-md items-center px-4 py-20">
    <form class="panel-pad w-full" @submit.prevent="submit">
      <div class="mb-6">
        <div class="text-xs font-bold uppercase tracking-[.2em] text-accent">Triathlon Timing</div>
        <h1 class="mt-2 text-2xl font-black">Reset your password</h1>
        <p class="mt-2 text-sm muted">Enter the email address connected to your athlete or official account.</p>
      </div>
      <label for="forgot-email" class="label">Email</label>
      <input id="forgot-email" v-model="form.email" class="field" type="email" autocomplete="email" required>
      <p v-if="form.errors.email" class="mt-2 text-sm text-error">{{ form.errors.email }}</p>
      <button class="btn-primary mt-5 w-full" :disabled="form.processing">Send reset link</button>
      <Link href="/login" class="mt-4 block text-center text-sm text-accent hover:text-accent">Back to sign in</Link>
    </form>
  </main>
</template>
