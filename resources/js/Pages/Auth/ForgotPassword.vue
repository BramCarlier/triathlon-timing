<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '' });
const submit = () => form.post('/forgot-password');
</script>

<template>
  <Head title="Forgot password" />
  <main class="mx-auto flex min-h-screen max-w-md items-center px-4 py-10">
    <form class="panel-pad w-full" @submit.prevent="submit">
      <div class="mb-6">
        <div class="text-xs font-bold uppercase tracking-[.2em] text-cyan-300">Triathlon Timing</div>
        <h1 class="mt-2 text-2xl font-black">Reset your password</h1>
        <p class="mt-2 text-sm muted">Enter the email address connected to your athlete or organizer account.</p>
      </div>
      <label class="label">Email</label>
      <input v-model="form.email" class="field" type="email" autocomplete="email" required>
      <p v-if="form.errors.email" class="mt-2 text-sm text-red-300">{{ form.errors.email }}</p>
      <button class="btn-primary mt-5 w-full" :disabled="form.processing">Send reset link</button>
      <Link href="/login" class="mt-4 block text-center text-sm text-cyan-300 hover:text-cyan-200">Back to sign in</Link>
    </form>
  </main>
</template>
