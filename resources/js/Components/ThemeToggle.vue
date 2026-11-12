<script setup lang="ts">
import Icon from './Icon.vue';
import { computed } from 'vue';
import { theme, toggleTheme } from '../theme';
import { tr } from '../i18n';
const label = computed(() => (theme.value === 'dark' ? tr('Switch to light mode') : tr('Switch to dark mode')));
</script>
<template>
  <button type="button" class="btn-icon shrink-0" :aria-label="label" :title="label" @click="toggleTheme">
    <Icon :name="theme === 'dark' ? 'sun' : 'moon'" />
  </button>
</template>
