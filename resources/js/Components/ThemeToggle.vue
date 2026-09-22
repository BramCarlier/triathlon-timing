<script setup lang="ts">
import { theme, toggleTheme } from '../theme';
</script>
<template><button type="button" class="btn-secondary shrink-0 !px-3 text-sm" :aria-label="`Switch to ${theme==='dark'?'light':'dark'} mode`" @click="toggleTheme"><span aria-hidden="true" class="mr-2">{{ theme==='dark'?'☀':'☾' }}</span>{{ theme==='dark'?'Light mode':'Dark mode' }}</button></template>
