<script setup lang="ts">
import Icon from '../Components/Icon.vue';
import Logo from '../Components/Logo.vue';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref, watch, onMounted, onBeforeUnmount } from 'vue';
import type { PageProps, Race } from '../types';
import ThemeToggle from '../Components/ThemeToggle.vue';
import LocaleSwitcher from '../Components/LocaleSwitcher.vue';
import RaceNavigation from '../Components/RaceNavigation.vue';
import FlashMessage from '../Components/FlashMessage.vue';

defineProps<{ title?: string; publicView?: boolean; displayMode?: boolean }>();
const menuOpen = ref(false);
const header = ref<HTMLElement>();
const headerHeight = ref(64);
let headerObserver: ResizeObserver | undefined;
onMounted(() => {
  headerObserver = new ResizeObserver(() => {
    headerHeight.value = header.value?.offsetHeight ?? 0;
  });
  if (header.value) headerObserver.observe(header.value);
});
onBeforeUnmount(() => headerObserver?.disconnect());
const page = usePage<PageProps>();
watch(
  () => page.url.split('?')[0],
  () => {
    menuOpen.value = false;
  },
);
const active = (href: string) => page.url.split('?')[0] === href || page.url.startsWith(`${href}/`);
const user = computed(() => page.props.auth.user);
const flash = computed(() => page.props.flash);
const race = computed(() => page.props.race as Race | undefined);
const logout = () => router.post('/logout');
const navLink =
  'inline-flex min-h-11 items-center gap-2 rounded-md px-3 py-2 font-semibold text-on-ink/85 hover:bg-white/10 hover:text-on-ink aria-[current=page]:bg-white/12 aria-[current=page]:text-on-ink aria-[current=page]:shadow-[inset_0_-3px_0_var(--app-buoy)]';
const menuLink = 'inline-flex min-h-11 items-center gap-2 rounded-md px-3 py-2 text-sm text-foreground hover:bg-raised';
</script>

<template>
  <div
    class="app-shell min-h-screen"
    :style="{ '--app-header-height': displayMode ? '0px' : `${headerHeight}px` }"
    @keydown.esc="menuOpen = false"
  >
    <a
      href="#main-content"
      class="sr-only focus:not-sr-only focus:fixed focus:left-4 focus:top-4 focus:z-50 focus:rounded-md focus:bg-surface focus:p-4"
    >
      {{ $t('Skip to content') }}
    </a>
    <header v-if="!displayMode" ref="header" class="app-header sticky top-0 z-50 bg-ink text-on-ink">
      <div class="app-container mx-auto flex max-w-7xl flex-wrap items-center gap-3 py-2.5">
        <Link
          :href="publicView ? page.url.split('?')[0] : '/dashboard'"
          class="rounded-md text-on-ink"
          :aria-label="$t('Triathlon Timing home')"
        >
          <Logo :with-name="true" class="[&>span:last-child]:hidden sm:[&>span:last-child]:inline" />
        </Link>
        <div v-if="user && !publicView" class="ml-auto flex items-center gap-2 xl:order-last xl:ml-0">
          <LocaleSwitcher class="header-control" />
          <button
            class="btn-icon header-control xl:hidden"
            :aria-label="$t('Menu')"
            :title="$t('Menu')"
            :aria-expanded="menuOpen"
            aria-controls="main-navigation"
            @click="menuOpen = !menuOpen"
          >
            <Icon :name="menuOpen ? 'xmark' : 'bars'" />
          </button>
        </div>
        <nav
          v-if="user && !publicView"
          id="main-navigation"
          :aria-label="$t('Main navigation')"
          class="order-last max-h-[70dvh] w-full flex-wrap items-center gap-1 overflow-y-auto pb-2 xl:order-none xl:ml-auto xl:flex xl:max-h-none xl:w-auto xl:justify-end xl:overflow-visible xl:pb-0"
          :class="menuOpen ? 'flex' : 'hidden'"
          @click="menuOpen = false"
        >
          <Link v-if="user.role !== 'athlete'" :aria-current="active('/races') ? 'page' : undefined" href="/races" :class="navLink">
            <Icon name="flag-checkered" />
            {{ $t('Races') }}
          </Link>
          <Link v-else :aria-current="active('/athlete') ? 'page' : undefined" href="/athlete" :class="navLink">
            <Icon name="person-running" />
            {{ $t('My races') }}
          </Link>
          <Link v-if="user.role === 'admin'" :aria-current="active('/users') ? 'page' : undefined" href="/users" :class="navLink">
            <Icon name="users" />
            {{ $t('People') }}
          </Link>
          <Link :aria-current="active('/guide') ? 'page' : undefined" href="/guide" :class="navLink">
            <Icon name="circle-question" />
            {{ $t('Help') }}
          </Link>
          <details class="relative w-full xl:w-auto" @toggle="menuOpen = true">
            <summary :class="navLink" class="w-full list-none xl:w-auto">
              <Icon name="user" />
              {{ $t('Account') }}
              <Icon name="chevron-down" class="size-4" />
            </summary>
            <div
              class="mt-1 grid gap-1 rounded-lg border border-outline bg-surface p-2 text-foreground shadow-2xl xl:absolute xl:right-0 xl:z-40 xl:min-w-60"
            >
              <p class="px-3 pb-1 pt-2 text-sm muted">{{ user.name }}</p>
              <Link href="/account/password" :class="menuLink">
                <Icon name="key" />
                {{ $t('Change password') }}
              </Link>
              <Link v-if="user.role === 'admin'" href="/admin/roles" :class="menuLink">
                <Icon name="shield-halved" />
                {{ $t('Official access') }}
              </Link>
              <Link v-if="user.role === 'admin'" href="/admin/health" :class="menuLink">
                <Icon name="heart-pulse" />
                {{ $t('System health') }}
              </Link>
              <Link v-if="user.role === 'admin'" href="/admin/deleted-races" :class="menuLink">
                <Icon name="trash-can-arrow-up" />
                {{ $t('Deleted races') }}
              </Link>
              <div class="flex min-h-11 items-center justify-between gap-3 rounded-md px-3 py-1 text-sm">
                <span class="inline-flex items-center gap-2">
                  <Icon name="circle-half-stroke" />
                  {{ $t('Appearance') }}
                </span>
                <ThemeToggle />
              </div>
              <button :class="menuLink" class="text-left" @click="logout">
                <Icon name="right-from-bracket" />
                {{ $t('Log out') }}
              </button>
            </div>
          </details>
        </nav>
        <div v-if="publicView || !user" class="ml-auto flex items-center gap-2">
          <LocaleSwitcher class="header-control" />
          <ThemeToggle class="header-control" />
        </div>
      </div>
    </header>
    <main id="main-content" tabindex="-1" class="app-container app-main mx-auto max-w-7xl py-5 sm:py-8">
      <h1 v-if="title" class="mb-4 text-3xl sm:text-4xl">{{ title }}</h1>
      <RaceNavigation v-if="!publicView && !displayMode && race && user && user.role !== 'athlete'" :race="race" />
      <FlashMessage :success="flash.success" :error="flash.error" />
      <slot />
    </main>
  </div>
</template>

<style>
.header-control {
  background: transparent !important;
  color: inherit !important;
  border-color: rgb(255 255 255 / 0.28) !important;
}
.header-control:hover {
  background: rgb(255 255 255 / 0.1) !important;
}
.header-control select option {
  color: var(--app-foreground);
  background: var(--app-surface);
}
</style>
