<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import type { PageProps, Race } from '../types';
import ThemeToggle from '../Components/ThemeToggle.vue';
import RaceNavigation from '../Components/RaceNavigation.vue';
import FlashMessage from '../Components/FlashMessage.vue';
defineProps<{ title?: string; publicView?: boolean; displayMode?: boolean }>();
const menuOpen=ref(false);
const header=ref<HTMLElement>();
const headerHeight=ref(70);
let headerObserver:ResizeObserver|undefined;
onMounted(()=>{headerObserver=new ResizeObserver(()=>{headerHeight.value=header.value?.offsetHeight??0;});if(header.value)headerObserver.observe(header.value);});
onBeforeUnmount(()=>headerObserver?.disconnect());
const page = usePage<PageProps>();
watch(()=>page.url.split('?')[0],()=>{menuOpen.value=false;});
const active=(href:string)=>page.url.split('?')[0]===href || page.url.startsWith(`${href}/`);
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash);
const race = computed(() => page.props.race as Race | undefined);
const logout = () => router.post('/logout');
</script>
<template>
  <div class="app-shell min-h-screen app-background" :style="{'--app-header-height':`${headerHeight}px`}" @keydown.esc="menuOpen=false">
    <a href="#main-content" class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-xl focus:bg-surface focus:p-4">Skip to content</a>
    <header ref="header" v-if="!displayMode" class="app-header sticky top-0 z-30 border-b border-outline/80 bg-canvas/90 backdrop-blur">
      <div class="app-container mx-auto flex max-w-7xl flex-wrap items-center gap-3 px-4 py-3 sm:px-6">
        <Link :href="publicView ? page.url.split('?')[0] : '/dashboard'" class="flex items-center gap-3 font-bold"><span class="grid size-9 place-items-center rounded-xl bg-cyan-400 text-slate-950">T</span><span class="hidden sm:inline">Triathlon Timing</span></Link>
        <button v-if="user && !publicView" class="btn-secondary ml-auto xl:hidden" :aria-expanded="menuOpen" aria-controls="main-navigation" @click="menuOpen=!menuOpen">Menu</button>
        <nav v-if="user && !publicView" id="main-navigation" aria-label="Main navigation" class="order-last max-h-[60dvh] overflow-y-auto w-full flex-wrap items-center gap-1 xl:order-none xl:ml-auto xl:flex xl:w-auto xl:justify-end xl:gap-2" :class="menuOpen?'flex':'hidden'">
          <Link v-if="user.role !== 'athlete'" :aria-current="active('/races')?'page':undefined" href="/races" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm text-secondary hover:bg-raised">Races</Link>
          <Link v-if="user.role === 'admin'" :aria-current="active('/users')?'page':undefined" href="/users" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm text-secondary hover:bg-raised">Users</Link>
          <Link v-if="user.role === 'admin'" href="/admin/roles" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm text-secondary hover:bg-raised">Roles & permissions</Link>
          <Link v-if="user.role === 'admin'" href="/admin/health" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm text-secondary hover:bg-raised">Health</Link>
          <Link v-if="user.role === 'athlete'" href="/athlete" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm text-secondary hover:bg-raised">My race</Link>
          <Link href="/account/password" class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm text-secondary hover:bg-raised">Account</Link>
          <button class="inline-flex min-h-11 items-center rounded-lg px-3 py-2 text-sm text-muted hover:bg-raised" @click="logout">Log out</button>
        </nav>
        <div :class="publicView || !user ? 'ml-auto' : ''"><ThemeToggle /></div>
      </div>
    </header>
    <main id="main-content" tabindex="-1" class="app-container app-main mx-auto max-w-7xl px-4 py-6 sm:px-6 sm:py-8">
      <div v-if="title" class="mb-6"><h1 class="text-2xl font-bold sm:text-3xl">{{ title }}</h1></div>
      <FlashMessage :success="flash.success" :error="flash.error" />
      <RaceNavigation v-if="!publicView && !displayMode && race && user && user.role !== 'athlete'" :race="race" />
      <slot />
    </main>
  </div>
</template>
