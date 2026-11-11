<script setup lang="ts">
import { computed, ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import Icon from '../Icon.vue';
import ConfirmDialog from '../ConfirmDialog.vue';

/** The account-free link timers open on their phones, with on/off and a fresh link. */
const props = defineProps<{ raceId: number; url: string; enabled: boolean; rotatedAt?: string | null }>();

const absolute = computed(() => new URL(props.url, window.location.origin).toString());
const copied = ref(false);
const copy = async () => {
  try {
    await navigator.clipboard.writeText(absolute.value);
    copied.value = true;
    window.setTimeout(() => { copied.value = false; }, 2500);
  } catch { copied.value = false; }
};
const share = async () => {
  try { await navigator.share({ title: document.title, url: absolute.value }); } catch { /* dismissed */ }
};
const canShare = typeof navigator !== 'undefined' && 'share' in navigator;

const toggleForm = useForm({ enabled: !props.enabled });
const toggle = () => { toggleForm.enabled = !props.enabled; toggleForm.put(`/races/${props.raceId}/race-day-link`, { preserveScroll: true }); };
const rotating = ref(false);
const rotateForm = useForm({});
const rotate = () => rotateForm.post(`/races/${props.raceId}/race-day-link/rotate`, { preserveScroll: true, onSuccess: () => { rotating.value = false; } });
</script>

<template>
  <section class="rounded-lg border-2 p-4 sm:p-5" :class="enabled ? 'border-buoy/60 bg-buoy/5' : 'border-outline bg-raised/40'" :aria-label="$t('Race-day link')">
    <div class="flex flex-col gap-4 lg:flex-row lg:items-start lg:justify-between">
      <div class="min-w-0">
        <h3 class="text-2xl">{{ $t('Race-day link') }}</h3>
        <p class="mt-1 max-w-prose text-secondary">{{ enabled
          ? $t('Share this link with anyone helping at the race. They choose the transition or finish and tap the correct athlete to record the time.')
          : $t('The link is switched off. Nobody can record times with it until you switch it on again.') }}</p>
        <p class="mt-2 truncate font-mono text-sm muted" :title="absolute">{{ absolute }}</p>
      </div>
      <div class="flex shrink-0 flex-wrap gap-2">
        <a :href="url" target="_blank" rel="noopener" class="btn-primary"><Icon name="arrow-up-right-from-square"/>{{ $t('Open race-day view') }}</a>
        <button type="button" class="btn-secondary" @click="copy"><Icon :name="copied ? 'check' : 'link'"/>{{ copied ? $t('Copied') : $t('Copy link') }}</button>
        <button v-if="canShare" type="button" class="btn-secondary" @click="share"><Icon name="share"/>{{ $t('Share') }}</button>
      </div>
    </div>
    <div class="mt-4 flex flex-wrap items-center gap-2 border-t border-outline pt-3 text-sm">
      <button type="button" class="btn-quiet !min-h-10" :disabled="toggleForm.processing" @click="toggle"><Icon name="power"/>{{ enabled ? $t('Switch link off') : $t('Switch link on') }}</button>
      <button type="button" class="btn-quiet !min-h-10" @click="rotating = true"><Icon name="rotate"/>{{ $t('Create a new link') }}</button>
      <span v-if="rotatedAt" class="muted">{{ $t('Link last renewed :date', { date: new Date(rotatedAt).toLocaleString() }) }}</span>
    </div>
    <ConfirmDialog v-if="rotating" :title="$t('Create a new race-day link?')" :message="$t('The current link stops working immediately. Everyone who is timing needs the new link.')" :confirm-label="$t('Create new link')" :busy="rotateForm.processing" @cancel="rotating = false" @confirm="rotate"/>
  </section>
</template>
