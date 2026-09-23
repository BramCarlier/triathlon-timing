<script setup lang="ts">
withDefaults(defineProps<{ name:string; size?:number }>(), { size:18 });
</script>

<template>
  <svg
    :width="size"
    :height="size"
    viewBox="0 0 24 24"
    fill="none"
    stroke="currentColor"
    stroke-width="2"
    stroke-linecap="round"
    stroke-linejoin="round"
    class="shrink-0"
    aria-hidden="true"
  >
    <template v-if="name==='search'"><circle cx="11" cy="11" r="8"/><path d="m21 21-4.35-4.35"/></template>
    <template v-else-if="name==='edit'"><path d="M12 20h9"/><path d="M16.5 3.5a2.12 2.12 0 0 1 3 3L7 19l-4 1 1-4Z"/></template>
    <template v-else-if="name==='trash'"><path d="M3 6h18"/><path d="M8 6V4h8v2"/><path d="M19 6l-1 14H6L5 6"/><path d="M10 11v5M14 11v5"/></template>
    <template v-else-if="name==='plus'"><path d="M12 5v14M5 12h14"/></template>
    <template v-else-if="name==='upload'"><path d="M12 3v12"/><path d="m7 8 5-5 5 5"/><path d="M5 21h14"/></template>
    <template v-else-if="name==='download'"><path d="M12 3v12"/><path d="m7 10 5 5 5-5"/><path d="M5 21h14"/></template>
    <template v-else-if="name==='save'"><path d="M5 3h12l2 2v16H5Z"/><path d="M8 3v6h8V3M8 21v-7h8v7"/></template>
    <template v-else-if="name==='close'"><path d="M6 6l12 12M18 6 6 18"/></template>
    <template v-else-if="name==='chevron-left'"><path d="m15 18-6-6 6-6"/></template>
    <template v-else-if="name==='chevron-right'"><path d="m9 18 6-6-6-6"/></template>
    <template v-else-if="name==='arrow-left'"><path d="m15 18-6-6 6-6"/><path d="M9 12h12"/></template>
    <template v-else-if="name==='play'"><path d="m8 5 11 7-11 7Z"/></template>
    <template v-else-if="name==='stop'"><rect x="6" y="6" width="12" height="12" rx="1"/></template>
    <template v-else-if="name==='refresh'"><path d="M20 7h-6V1"/><path d="M20 7a9 9 0 1 0 2 5"/></template>
    <template v-else-if="name==='undo'"><path d="M9 7 4 12l5 5"/><path d="M4 12h9a7 7 0 0 1 7 7"/></template>
    <template v-else-if="name==='users'"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87M16 3.13a4 4 0 0 1 0 7.75"/></template>
    <template v-else-if="name==='eye'"><path d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7S2 12 2 12Z"/><circle cx="12" cy="12" r="3"/></template>
    <template v-else-if="name==='fullscreen'"><path d="M8 3H3v5M16 3h5v5M8 21H3v-5M16 21h5v-5"/></template>
    <template v-else-if="name==='log-in'"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="m10 17 5-5-5-5M15 12H3"/></template>
    <template v-else-if="name==='log-out'"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="m16 17 5-5-5-5M21 12H9"/></template>
    <template v-else-if="name==='menu'"><path d="M4 6h16M4 12h16M4 18h16"/></template>
    <template v-else-if="name==='moon'"><path d="M21 12.8A9 9 0 1 1 11.2 3a7 7 0 0 0 9.8 9.8Z"/></template>
    <template v-else-if="name==='sun'"><circle cx="12" cy="12" r="4"/><path d="M12 2v2M12 20v2M4.93 4.93l1.41 1.41M17.66 17.66l1.41 1.41M2 12h2M20 12h2M4.93 19.07l1.41-1.41M17.66 6.34l1.41-1.41"/></template>
    <template v-else-if="name==='mail'"><rect x="3" y="5" width="18" height="14" rx="2"/><path d="m3 7 9 6 9-6"/></template>
    <template v-else-if="name==='check'"><path d="m5 12 4 4L19 6"/></template>
    <template v-else-if="name==='monitor'"><rect x="2" y="3" width="20" height="14" rx="2"/><path d="M8 21h8M12 17v4"/></template>
    <template v-else-if="name==='file'"><path d="M6 2h9l5 5v15H6Z"/><path d="M14 2v6h6"/></template>
    <template v-else><circle cx="12" cy="12" r="9"/><path d="M12 8v4M12 16h.01"/></template>
  </svg>
</template>
