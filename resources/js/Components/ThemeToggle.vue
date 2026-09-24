<script setup lang="ts">
import { computed } from 'vue';
import { theme, toggleTheme } from '../theme';
import { tr } from '../i18n';
const label = computed(() => theme.value === 'dark' ? tr('Switch to light mode') : tr('Switch to dark mode'));
</script>
<template><button type="button" class="btn-icon shrink-0" :aria-label="label" :title="label" @click="toggleTheme"><i :class="theme==='dark'?'fa-solid fa-sun':'fa-solid fa-moon'" aria-hidden="true"></i></button></template>
