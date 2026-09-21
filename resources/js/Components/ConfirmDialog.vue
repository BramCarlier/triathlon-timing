<script setup lang="ts">
import { onMounted, ref } from 'vue';
defineProps<{ title: string; message: string; confirmLabel: string; busy?: boolean }>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();
const dialog = ref<HTMLDialogElement>();
onMounted(() => dialog.value?.showModal());
</script>
<template>
  <dialog ref="dialog" aria-labelledby="confirmation-title" aria-describedby="confirmation-message"
    class="m-auto w-[calc(100%-2rem)] max-w-lg rounded-2xl border border-slate-700 bg-slate-900 p-6 text-slate-100 shadow-2xl backdrop:bg-black/70"
    @cancel.prevent="!busy && emit('cancel')">
    <h2 id="confirmation-title" class="text-xl font-bold">{{ title }}</h2>
    <p id="confirmation-message" class="mt-3 text-slate-300">{{ message }}</p>
    <slot />
    <div class="mt-6 flex flex-wrap justify-end gap-3">
      <button autofocus class="btn-secondary" :disabled="busy" @click="emit('cancel')">Cancel</button>
      <button class="btn-primary" :disabled="busy" @click="emit('confirm')">{{ confirmLabel }}</button>
    </div>
  </dialog>
</template>
