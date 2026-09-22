<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { PageProps, Race } from '../types';
import RaceNavigation from '../Components/RaceNavigation.vue';
import FlashMessage from '../Components/FlashMessage.vue';
defineProps<{ title?: string }>();
const page = usePage<PageProps>();
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash);
const race = computed(() => page.props.race as Race | undefined);
const logout = () => router.post('/logout');
</script>
<template>
  <div class="min-h-screen bg-[radial-gradient(circle_at_top,_#10243d_0,_#07111f_45%,_#050a12_100%)]">
    <header class="sticky top-0 z-30 border-b border-slate-800/80 bg-slate-950/90 backdrop-blur">
      <div class="mx-auto flex max-w-7xl items-center gap-4 px-4 py-3 sm:px-6">
        <Link href="/dashboard" class="flex items-center gap-3 font-bold"><span class="grid size-9 place-items-center rounded-xl bg-cyan-400 text-slate-950">T</span><span class="hidden sm:inline">Triathlon Timing</span></Link>
        <nav v-if="user" aria-label="Main navigation" class="ml-auto flex flex-wrap items-center justify-end gap-1 sm:gap-2">
          <Link v-if="user.role !== 'athlete'" href="/races" class="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Races</Link>
          <Link v-if="user.role === 'admin'" href="/users" class="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Users</Link>
          <Link v-if="user.role === 'admin'" href="/admin/health" class="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Health</Link>
          <Link v-if="user.role === 'athlete'" href="/athlete" class="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">My race</Link>
          <Link href="/account/password" class="rounded-lg px-3 py-2 text-sm text-slate-300 hover:bg-slate-800">Account</Link>
          <button class="rounded-lg px-3 py-2 text-sm text-slate-400 hover:bg-slate-800" @click="logout">Log out</button>
        </nav>
      </div>
    </header>
    <main class="mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">
      <div v-if="title" class="mb-6"><h1 class="text-2xl font-bold sm:text-3xl">{{ title }}</h1></div>
      <FlashMessage :success="flash.success" :error="flash.error" />
      <RaceNavigation v-if="race && user && user.role !== 'athlete'" :race="race" />
      <slot />
    </main>
  </div>
</template>
