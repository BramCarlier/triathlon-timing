<script setup lang="ts">
import { theme, toggleTheme } from '../theme';
</script>
<template><button type="button" class="btn-icon shrink-0" :aria-label="`Switch to ${theme==='dark'?'light':'dark'} mode`" :title="`Switch to ${theme==='dark'?'light':'dark'} mode`" @click="toggleTheme"><i :class="theme==='dark'?'fa-solid fa-sun':'fa-solid fa-moon'" aria-hidden="true"></i></button></template>
