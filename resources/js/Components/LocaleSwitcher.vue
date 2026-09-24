<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { activeLocale, setLocale, type Locale } from '../i18n';

function change(event: Event) {
  const locale = (event.target as HTMLSelectElement).value as Locale;
  setLocale(locale);
  router.post('/locale', { locale }, { preserveScroll: true, preserveState: true });
}
</script>

<template>
  <label class="relative inline-flex min-h-11 items-center gap-2 rounded-lg border border-outline-strong bg-raised px-2.5 text-sm font-semibold text-secondary">
    <i class="fa-solid fa-language" aria-hidden="true"></i>
    <span class="sr-only">{{ $t('Language') }}</span>
    <select
      :value="activeLocale"
      class="appearance-none bg-transparent pr-5 font-semibold outline-none"
      :aria-label="$t('Language')"
      @change="change"
    >
      <option value="en">EN</option>
      <option value="nl">NL</option>
    </select>
    <i class="fa-solid fa-chevron-down pointer-events-none absolute right-2 text-[10px]" aria-hidden="true"></i>
  </label>
</template>
