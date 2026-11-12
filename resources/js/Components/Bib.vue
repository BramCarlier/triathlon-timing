<script setup lang="ts">
import { computed } from 'vue';
import { tr } from '../i18n';

const props = defineProps<{ bib: string | null | undefined; small?: boolean }>();
const label = computed(() => (props.bib ? tr('Bib :bib', { bib: props.bib }) : tr('No bib')));
</script>

<template>
  <span class="bib" :class="{ 'bib-empty': !bib, 'bib-sm': small }" :aria-label="label" :title="label">{{ bib || $t('No bib') }}</span>
</template>
