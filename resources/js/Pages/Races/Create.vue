<script setup lang="ts">
import { Head, useForm } from '@inertiajs/vue3';
import AppLayout from '../../Layouts/AppLayout.vue';

const form = useForm({
  name: '',
  event_date: '',
  timezone: 'Europe/Brussels',
  swim_km: 1,
  bike_km: 35,
  run_km: 8,
});
const submit = () => form.post('/races');
</script>

<template>
  <Head :title="$t('Create race')"/>
  <AppLayout :title="$t('Create race')">
    <form class="panel-pad max-w-2xl" @submit.prevent="submit">
      <div class="mb-5 rounded-2xl bg-canvas p-4">
        <div class="flex items-start gap-3">
          <span class="grid size-9 shrink-0 place-items-center rounded-xl bg-cyan-400 font-bold text-slate-950">1</span>
          <div>
            <h2 class="font-bold">{{ $t("Start with the essentials") }}</h2>
            <p class="mt-1 text-sm muted">{{ $t("After creating the race you stay in one workspace to review checkpoints, add athletes and run race day.") }}</p>
          </div>
        </div>
      </div>

      <div>
        <label for="race-name" class="label">{{ $t("Race name") }}</label>
        <input id="race-name" v-model="form.name" class="field min-h-12" :placeholder="$t('Halle Triathlon 2027')" required autofocus>
        <p class="text-sm text-error">{{ form.errors.name }}</p>
      </div>

      <details class="mt-5 rounded-2xl border border-outline p-4">
        <summary class="cursor-pointer font-semibold"><i class="fa-solid fa-sliders mr-2" aria-hidden="true"></i>{{ $t("Date, distances & other optional details") }}</summary>
        <p class="mt-2 text-sm muted">{{ $t("You can create the race with only a name. If the date is left blank, today is used. The standard distances stay prefilled at 1 km swim, 35 km bike and 8 km run.") }}</p>
        <div class="mt-4 grid gap-4 sm:grid-cols-2">
          <div>
            <label for="race-date" class="label">{{ $t("Race date") }} <span class="font-normal muted">{{ $t("(optional)") }}</span></label>
            <input id="race-date" v-model="form.event_date" type="date" class="field">
            <p class="text-sm text-error">{{ form.errors.event_date }}</p>
          </div>
          <div>
            <label for="race-timezone" class="label">{{ $t("Timezone") }}</label>
            <input id="race-timezone" v-model="form.timezone" class="field" required>
          </div>
          <div><label for="swim-km" class="label">{{ $t("Swim distance (km)") }}</label><input id="swim-km" v-model="form.swim_km" type="number" min="0" step="0.001" class="field"></div>
          <div><label for="bike-km" class="label">{{ $t("Bike distance (km)") }}</label><input id="bike-km" v-model="form.bike_km" type="number" min="0" step="0.001" class="field"></div>
          <div><label for="run-km" class="label">{{ $t("Run distance (km)") }}</label><input id="run-km" v-model="form.run_km" type="number" min="0" step="0.001" class="field"></div>
        </div>
      </details>

      <p v-if="Object.keys(form.errors).length" class="mt-4 text-sm text-error">{{ Object.values(form.errors)[0] }}</p>
      <button class="btn-primary mt-6" :disabled="form.processing">
        <i class="fa-solid fa-arrow-right" aria-hidden="true"></i>{{ $t("Create and continue") }}
      </button>
    </form>
  </AppLayout>
</template>
