<script setup lang="ts">
import Icon from './Icon.vue';
import { ref } from 'vue';
import { useForm } from '@inertiajs/vue3';
import ConfirmDialog from './ConfirmDialog.vue';

const props = defineProps<{ raceId: number; timingId: number; label: string }>();
const confirming = ref(false);
const form = useForm({});
const remove = () =>
  form.post(`/races/${props.raceId}/timings/${props.timingId}/void`, {
    preserveScroll: true,
    onSuccess: () => {
      confirming.value = false;
    },
  });
</script>

<template>
  <button
    type="button"
    class="btn-quiet !min-h-10 !px-2.5 shrink-0 text-error hover:!bg-error/10"
    :aria-label="$t('Delete time: :label', { label })"
    :title="$t('Delete time')"
    @click="confirming = true"
  >
    <Icon name="trash-can" />
  </button>
  <Teleport to="body">
    <ConfirmDialog
      v-if="confirming"
      :title="$t('Delete time registration?')"
      :message="label"
      :confirm-label="$t('Delete time')"
      :busy="form.processing"
      @cancel="confirming = false"
      @confirm="remove"
    >
      <p class="mt-3 text-sm muted">
        {{ $t('This time will be removed from the results and live standings. A record remains in the audit history.') }}
      </p>
      <p v-for="(error, key) in form.errors" :key="key" role="alert" class="mt-3 text-error">{{ error }}</p>
    </ConfirmDialog>
  </Teleport>
</template>
