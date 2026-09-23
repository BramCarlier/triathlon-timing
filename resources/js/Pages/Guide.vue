<script setup lang="ts">
import { computed } from 'vue';
import { Head, Link, usePage } from '@inertiajs/vue3';
import AppLayout from '../Layouts/AppLayout.vue';
import type { PageProps, UserRole } from '../types';

const props = defineProps<{ role: UserRole }>();
const page = usePage<PageProps>();
const permissions = computed(() => page.props.auth.user?.permissions ?? []);
const can = (permission: string) => props.role === 'admin' || permissions.value.includes(permission);
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
          <h2 class="text-xl font-bold">What you need to know</h2>
          <p v-if="role==='admin'" class="mt-2 muted">You prepare races, assign officials, run race day and handle exceptions. The normal workflow now lives on one race page.</p>
          <p v-else-if="role==='organizer'" class="mt-2 muted">You only need the parts of an assigned race your permissions allow. On race day, the important job is usually choosing your checkpoint and recording athletes as they pass.</p>
          <p v-else class="mt-2 muted">Use My race to see your event information, progress and available results. Administrative race controls are intentionally hidden from athlete accounts.</p>
        </div>
      </div>
    </section>

    <template v-if="role==='admin'">
      <section class="grid gap-4 lg:grid-cols-2">
        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">1</span><h2 class="text-lg font-bold">Create and prepare the race</h2></div>
          <p class="muted">Open <strong>Races</strong>, create the event, then stay on its Race workspace. Confirm the course, checkpoints and participants there.</p>
          <Link href="/races" class="btn-primary mt-4"><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i>Open races</Link>
        </article>
        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">2</span><h2 class="text-lg font-bold">Prepare the people</h2></div>
          <p class="muted">Add participants manually or import them. Assign Officials to the race before race day so their assigned events appear automatically.</p>
          <Link href="/users" class="btn-secondary mt-4"><i class="fa-solid fa-users" aria-hidden="true"></i>Manage people</Link>
        </article>
        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">3</span><h2 class="text-lg font-bold">Run race day</h2></div>
          <p class="muted">Start the shared clock from the Race workspace. Choose a checkpoint, find an athlete or team, and tap them when they cross. Every official sees the same race clock.</p>
        </article>
        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">4</span><h2 class="text-lg font-bold">Finish and review</h2></div>
          <p class="muted">By default the race finishes automatically when every active participant has a finish time. You can turn that off and finish manually when needed. Results and corrections remain available afterwards.</p>
        </article>
      </section>

      <details class="panel-pad mt-5">
        <summary class="cursor-pointer font-bold">Occasional admin tasks</summary>
        <div class="mt-4 grid gap-3 sm:grid-cols-2">
          <Link href="/admin/roles" class="rounded-xl border border-outline p-4 hover:bg-raised">
            <strong><i class="fa-solid fa-key mr-2" aria-hidden="true"></i>Access & permissions</strong>
            <p class="mt-2 text-sm muted">Decide what an Official is allowed to set up, record or export.</p>
          </Link>
          <Link href="/admin/health" class="rounded-xl border border-outline p-4 hover:bg-raised">
            <strong><i class="fa-solid fa-heart-pulse mr-2" aria-hidden="true"></i>System health</strong>
            <p class="mt-2 text-sm muted">Technical checks for troubleshooting. You normally do not need this during a race.</p>
          </Link>
        </div>
      </details>
    </template>

    <template v-else-if="role==='organizer'">
      <section class="grid gap-4 lg:grid-cols-2">
        <article class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">1</span><h2 class="text-lg font-bold">Open your assigned race</h2></div>
          <p class="muted">Go to Races and open the event. The Race workspace only shows actions your account is allowed to use.</p>
          <Link href="/races" class="btn-primary mt-4"><i class="fa-solid fa-flag-checkered" aria-hidden="true"></i>Open my races</Link>
        </article>
        <article v-if="can('timings.record')" class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">2</span><h2 class="text-lg font-bold">Record your checkpoint</h2></div>
          <p class="muted">Choose the checkpoint you are standing at. Search by bib, athlete or team. Tap once as the participant passes; a recorded participant is clearly marked so you do not tap twice.</p>
        </article>
        <article v-if="can('races.control')" class="panel-pad">
          <div class="mb-3 flex items-center gap-3"><span class="badge">3</span><h2 class="text-lg font-bold">Shared clock controls</h2></div>
          <p class="muted">Your role allows starting and finishing the race. Confirm with the race director before using these controls because they affect every timing station.</p>
        </article>
        <article class="panel-pad">
          <h2 class="text-lg font-bold">If the connection is unreliable</h2>
          <p class="mt-2 muted">Use the focused Timing mode from the Race workspace. It can queue times on the device and upload them again when the connection returns.</p>
        </article>
      </section>

      <section class="panel-pad mt-5">
        <h2 class="font-bold">What your account can do</h2>
        <div class="mt-3 flex flex-wrap gap-2 text-sm">
          <span v-if="can('races.setup')" class="badge">Edit race setup</span>
          <span v-if="can('participants.manage')" class="badge">Manage participants</span>
          <span v-if="can('timings.record')" class="badge">Record times</span>
          <span v-if="can('races.control')" class="badge">Start / finish race</span>
          <span v-if="can('results.export')" class="badge">Export results</span>
        </div>
        <p class="mt-3 text-sm muted">If something you expect is missing, ask an Organizer (admin) to review your access.</p>
      </section>
    </template>

    <template v-else>
      <section class="grid gap-4 lg:grid-cols-2">
        <article class="panel-pad">
          <h2 class="text-lg font-bold">Before and during the race</h2>
          <p class="mt-2 muted">Open My race to see the event linked to your account. You do not need to choose checkpoints or operate the race clock.</p>
          <Link href="/athlete" class="btn-primary mt-4"><i class="fa-solid fa-person-running" aria-hidden="true"></i>Open my race</Link>
        </article>
        <article class="panel-pad">
          <h2 class="text-lg font-bold">Results</h2>
          <p class="mt-2 muted">When results are available, your race page shows your recorded progress and result information. Public result links may also be shared by the organizers.</p>
        </article>
      </section>
      <section class="panel-pad mt-5">
        <h2 class="font-bold">Need account help?</h2>
        <p class="mt-2 muted">Use Account to change your password. For incorrect race or athlete information, contact an event Organizer or Official.</p>
        <Link href="/account/password" class="btn-secondary mt-4"><i class="fa-solid fa-lock" aria-hidden="true"></i>Account settings</Link>
      </section>
    </template>
  </AppLayout>
</template>
