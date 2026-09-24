<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import type { UserRole } from '../types';

const props = defineProps<{ role: UserRole }>();
const roleLabel = computed(() => props.role === 'admin' ? 'Organizer (admin)' : props.role === 'organizer' ? 'Official' : 'Athlete');
</script>

<template>
  <Head :title="`${roleLabel} guide`" />
  <AppLayout :title="`${roleLabel} guide`">
    <section class="panel-pad mb-5">
      <div class="flex items-start gap-4">
        <span class="grid size-12 shrink-0 place-items-center rounded-2xl bg-cyan-400 text-xl text-slate-950">
          <i class="fa-solid fa-compass" aria-hidden="true"></i>
        </span>
        <div>
          <h2 class="text-xl font-bold">The simple race flow</h2>
          <p v-if="role==='admin'" class="mt-2 muted">Everything important happens from the Race workspace. Complete the setup from top to bottom, then the page switches into race-day mode.</p>
          <p v-else-if="role==='organizer'" class="mt-2 muted">Your Organizer assigns you to one checkpoint. On race day you only need to record athletes at that checkpoint.</p>
          <p v-else class="mt-2 muted">Your account is for viewing your race and results. You do not operate checkpoints or race controls.</p>
        </div>
      </div>
    </section>

    <template v-if="role==='admin'">
      <section class="grid gap-4 lg:grid-cols-2">
        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">1</span><h2 class="text-lg font-bold">Create the race</h2></div>
          <p class="muted">Enter the race name and date. The app creates the normal triathlon checkpoints so you can adjust them instead of building everything from scratch.</p>
          <Link href="/races" class="btn-primary mt-4"><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i>Open races</Link>
        </article>

        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">2</span><h2 class="text-lg font-bold">Course & Officials</h2></div>
          <p class="muted">Add or edit checkpoints, then assign an Official to each location. The form shows available Officials immediately; typing filters the list, and new details create an account only when no existing Official is selected.</p>
        </article>

        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">3</span><h2 class="text-lg font-bold">Add participants</h2></div>
          <p class="muted">Add solo participants or relay teams manually, or import a file. Existing athlete profiles appear automatically so the same person can be reused across races. Registration is locked as soon as the race starts.</p>
        </article>

        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">4</span><h2 class="text-lg font-bold">Start and record</h2></div>
          <p class="muted">Start the shared race clock. You can switch between all checkpoints when recording times. Each Official only sees the checkpoint assigned to their account and cannot switch it.</p>
        </article>

        <article class="panel-pad lg:col-span-2">
          <div class="mb-3 flex items-center gap-3"><span class="badge">5</span><h2 class="text-lg font-bold">End the race</h2></div>
          <p class="muted">There is no automatic-finish setting to configure. The backend closes the race when the last active athlete receives a finish time. If the event needs to end earlier, use <strong>End race now</strong>.</p>
        </article>
      </section>

      <details class="panel-pad mt-5">
        <summary class="cursor-pointer font-bold">Occasional admin tools</summary>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <Link href="/admin/roles" class="rounded-xl border border-outline p-4 hover:bg-raised">
            <strong><i class="fa-solid fa-key mr-2" aria-hidden="true"></i>Access & permissions</strong>
            <p class="mt-2 text-sm muted">Manage account roles when you need something beyond the standard Organizer, Official and Athlete workflow.</p>
          </Link>
          <Link href="/admin/health" class="rounded-xl border border-outline p-4 hover:bg-raised">
            <strong><i class="fa-solid fa-heart-pulse mr-2" aria-hidden="true"></i>System health</strong>
            <p class="mt-2 text-sm muted">Troubleshooting information. You normally do not need this during a race.</p>
          </Link>
        </div>
      </details>
    </template>

    <template v-else-if="role==='organizer'">
      <section class="grid gap-4 lg:grid-cols-2">
        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">1</span><h2 class="text-lg font-bold">Open your race</h2></div>
          <p class="muted">Open the race assigned to your account. Your checkpoint assignment follows your account, so you do not have to choose a station yourself.</p>
          <Link href="/races" class="btn-primary mt-4"><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i>Open my races</Link>
        </article>

        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">2</span><h2 class="text-lg font-bold">Wait for the start</h2></div>
          <p class="muted">The Organizer starts the shared race clock. Your timing station becomes active automatically when the race starts.</p>
        </article>

        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">3</span><h2 class="text-lg font-bold">Tap athletes as they pass</h2></div>
          <p class="muted">Search by bib or name and tap the athlete once. Your checkpoint is fixed; only the Organizer can switch between checkpoints.</p>
        </article>

        <article class="panel-pad">
          <h2 class="text-lg font-bold">Poor connection?</h2>
          <p class="mt-2 muted">Open your Timing station before moving to the checkpoint. It stores unsent timings on the device and synchronizes them when the connection returns.</p>
        </article>
      </section>
    </template>

    <template v-else>
      <section class="grid gap-4 lg:grid-cols-2">
        <article class="panel-pad">
          <h2 class="text-lg font-bold">Your races</h2>
          <p class="mt-2 muted">Open My races to see every event linked to your athlete profile. You do not need to choose checkpoints or operate the race clock.</p>
          <Link href="/athlete" class="btn-primary mt-4"><i class="fa-solid fa-person-running" aria-hidden="true"></i>Open my races</Link>
        </article>
        <article class="panel-pad">
          <h2 class="text-lg font-bold">Results</h2>
          <p class="mt-2 muted">Your race history shows recorded progress and results for each event when they become available.</p>
        </article>
      </section>
    </template>
  </AppLayout>
</template>
