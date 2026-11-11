<script setup lang="ts">
import Icon from './Icon.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { activeLocale, type Locale } from '../i18n';

const pending = ref(false);
function change(event: Event) {
  const select = event.target as HTMLSelectElement;
  const locale = select.value as Locale;
  pending.value = true;
  router.post(
    '/locale',
    { locale },
    {
      preserveScroll: true,
      preserveState: true,
      onFinish: () => {
        pending.value = false;
        select.value = activeLocale.value;
      },
    },
  );
}
</script>

<template>
  <label
    class="relative inline-flex min-h-11 items-center gap-2 rounded-md border border-outline-strong bg-surface px-2.5 text-sm font-semibold"
  >
    <Icon name="language" />
    <span class="sr-only">{{ $t('Language') }}</span>
    <select
      :value="activeLocale"
      :disabled="pending"
      class="appearance-none bg-transparent pr-5 font-semibold outline-none"
      :aria-label="$t('Language')"
      @change="change"
    >
      <option value="en">EN</option>
      <option value="nl">NL</option>
    </select>
    <Icon name="chevron-down" class="pointer-events-none absolute right-2 size-3.5" />
  </label>
</template>
