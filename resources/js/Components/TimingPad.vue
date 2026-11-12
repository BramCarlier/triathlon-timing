<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, ref, watch } from 'vue';
import Icon from './Icon.vue';
import Bib from './Bib.vue';
import RaceClock from './RaceClock.vue';
import { tr } from '../i18n';
import { bibLabel, formatDuration } from '../lib';
import type { TimingRecorder } from '../Composables/useTimingRecorder';
import type { Checkpoint, StationParticipant } from '../types';

/**
 * The tap screen shared by the race-day link, the Organizer workspace and the
 * timing station: a checkpoint band in the discipline colour, a search field
 * and the athletes still to come first.
 */
const props = withDefaults(
  defineProps<{
    race: { started_at?: string | null; finished_at?: string | null };
    serverNow: string;
    checkpoints: Checkpoint[];
    checkpointId: number | null;
    participants: StationParticipant[];
    recorder: TimingRecorder;
    /** The account is locked to one checkpoint; hide the switcher. */
    locked?: boolean;
    /** Keep the band below the app header while scrolling. */
    sticky?: boolean;
    /** Where the input mode preference is remembered. */
    storageKey?: string;
    /** Announce the band clock as the page's race timer (off when the page has its own). */
    timer?: boolean;
  }>(),
  { locked: false, sticky: true, storageKey: 'tt.keypad', timer: true },
);

const emit = defineEmits<{ 'update:checkpointId': [id: number] }>();

const checkpoint = computed(() => props.checkpoints.find(cp => cp.id === props.checkpointId) ?? null);
const search = ref('');
const searchInput = ref<HTMLInputElement>();
const choosing = ref(false);
const showRecorded = ref(false);

const readStoredMode = (): 'numeric' | 'text' | null => {
  try {
    const value = localStorage.getItem(props.storageKey);
    return value === 'numeric' || value === 'text' ? value : null;
  } catch {
    return null;
  }
};
const keypad = ref<'numeric' | 'text'>(readStoredMode() ?? (props.participants.some(p => p.bib_number) ? 'numeric' : 'text'));
const toggleKeypad = async () => {
  keypad.value = keypad.value === 'numeric' ? 'text' : 'numeric';
  try {
    localStorage.setItem(props.storageKey, keypad.value);
  } catch {
    /* private mode */
  }
  await nextTick();
  searchInput.value?.blur();
  searchInput.value?.focus();
};

const discipline = (value?: string | null) => (value === 'swim' || value === 'bike' || value === 'run' ? value : undefined);
const disciplineLabel = (value: string) => tr(value.charAt(0).toUpperCase() + value.slice(1));
const competing = computed(() => props.participants.filter(p => p.status !== 'dns'));

const matches = (p: StationParticipant, term: string) =>
  (p.bib_number ?? '').toLowerCase().includes(term) ||
  p.name.toLowerCase().includes(term) ||
  p.members.some(member => member.name.toLowerCase().includes(term));

const filtered = computed(() => {
  const term = search.value.trim().toLowerCase();
  const rows = term ? competing.value.filter(p => matches(p, term)) : competing.value;
  // Exact bib match first: typing "12" should put bib 12 above 112 and 120.
  return term
    ? [...rows].sort((a, b) => Number(b.bib_number?.toLowerCase() === term) - Number(a.bib_number?.toLowerCase() === term))
    : rows;
});
const toCome = computed(() => filtered.value.filter(p => !props.recorder.isRecorded(p)));
const done = computed(() => filtered.value.filter(p => props.recorder.isRecorded(p)));
const progress = computed(() => ({
  done: competing.value.filter(p => p.status === 'registered' && props.recorder.isRecorded(p)).length,
  total: competing.value.filter(p => p.status === 'registered').length,
}));

const open = computed(() => !!props.race.started_at && !props.race.finished_at && !!checkpoint.value);
const blocked = (p: StationParticipant) => !!p.status && p.status !== 'registered';
const stateText = (p: StationParticipant) =>
  blocked(p)
    ? (p.status ?? '').toUpperCase()
    : props.recorder.saving.value.has(p.id)
      ? tr('Saving')
      : props.recorder.isRecorded(p)
        ? tr('Recorded')
        : tr('Tap');
const rowLabel = (p: StationParticipant) => `${bibLabel(p.bib_number)} ${p.name} ${stateText(p)}`;
// For a relay, name the team member racing this leg first.
const subline = (p: StationParticipant) => {
  if (p.type !== 'relay') return p.category || tr('Solo athlete');
  const leg = checkpoint.value?.discipline;
  const members = [...p.members].sort((a, b) => Number(b.discipline === leg) - Number(a.discipline === leg));
  return members.map(member => `${disciplineLabel(member.discipline)}: ${member.name}`).join(', ');
};

const lastTapped = ref<number | null>(null);
async function tap(p: StationParticipant) {
  await props.recorder.record(p);
  if (props.recorder.isRecorded(p)) {
    lastTapped.value = p.id;
    search.value = '';
  }
}

function choose(id: number) {
  emit('update:checkpointId', id);
  choosing.value = false;
}

// Undo countdown for the latest tap.
const now = ref(Date.now());
const ticker = window.setInterval(() => {
  now.value = Date.now();
}, 500);
onBeforeUnmount(() => window.clearInterval(ticker));
const undoSecondsLeft = computed(() => {
  const until = props.recorder.feedback.value?.tap?.undo_until;
  if (!until) return null;
  return Math.max(0, Math.ceil((Date.parse(until) - now.value) / 1000));
});
const canUndo = computed(() => {
  const tapValue = props.recorder.feedback.value?.tap;
  if (!tapValue || props.recorder.feedback.value?.type === 'error') return false;
  return undoSecondsLeft.value === null || undoSecondsLeft.value > 0;
});

watch(
  () => props.checkpointId,
  () => {
    search.value = '';
    showRecorded.value = false;
  },
);
const chooser = ref<HTMLDialogElement>();
watch(choosing, value => {
  if (value && !chooser.value?.open) chooser.value?.showModal();
  if (!value && chooser.value?.open) chooser.value.close();
});
defineExpose({
  openChooser: () => {
    choosing.value = true;
  },
});
</script>

<template>
  <section class="timing-pad" :data-discipline="discipline(checkpoint?.discipline)" :data-kind="checkpoint?.kind">
    <div :class="sticky ? 'sticky z-20' : ''" :style="sticky ? 'top: var(--app-header-height, 0px)' : undefined">
      <div
        class="checkpoint-band rounded-t-lg px-4 py-3 sm:px-5"
        :data-discipline="discipline(checkpoint?.discipline)"
        :data-kind="checkpoint?.kind"
      >
        <div class="flex items-center justify-between gap-3">
          <button
            v-if="!locked && checkpoints.length > 1"
            type="button"
            class="-mx-2 flex min-w-0 flex-1 items-center gap-1.5 rounded-md px-2 py-1 text-left hover:bg-black/10"
            aria-haspopup="dialog"
            :aria-label="$t('Change checkpoint. Recording at :checkpoint', { checkpoint: $t(checkpoint?.name ?? '') })"
            @click="choosing = true"
          >
            <span class="min-w-0">
              <span class="block text-sm opacity-85">{{ $t('Recording at') }}</span>
              <strong class="display block truncate text-[1.75rem] leading-none sm:text-4xl">
                {{ checkpoint ? $t(checkpoint.name) : $t('Choose a checkpoint') }}
              </strong>
            </span>
            <Icon name="chevron-down" class="size-6 opacity-85" />
          </button>
          <div v-else class="min-w-0 flex-1">
            <span class="block text-sm opacity-85">{{ $t('Recording at') }}</span>
            <strong class="display block truncate text-[1.75rem] leading-none sm:text-4xl">
              {{ checkpoint ? $t(checkpoint.name) : $t('No checkpoint') }}
            </strong>
          </div>
          <div class="shrink-0 text-right">
            <RaceClock
              :started-at="race.started_at"
              :finished-at="race.finished_at"
              :server-now="serverNow"
              :milliseconds="false"
              compact
              :announce="timer"
            />
            <span class="text-sm tabular opacity-85">{{ $t(':done of :total here', progress) }}</span>
          </div>
        </div>
      </div>
      <div class="flex gap-2 border-x border-outline bg-surface p-2 sm:p-3">
        <label class="sr-only" for="timing-search">{{ $t('Find athlete') }}</label>
        <div class="relative min-w-0 flex-1">
          <Icon name="magnifying-glass" class="pointer-events-none absolute left-3 top-1/2 -translate-y-1/2 text-muted" />
          <input
            id="timing-search"
            ref="searchInput"
            v-model="search"
            type="search"
            class="field min-h-13 pl-10 text-xl"
            :inputmode="keypad"
            :enterkeyhint="'search'"
            autocomplete="off"
            :placeholder="$t('Bib number or name')"
            :aria-label="$t('Find athlete')"
          />
        </div>
        <button
          type="button"
          class="btn-secondary min-h-13 shrink-0 !px-3 tabular"
          :aria-label="keypad === 'numeric' ? $t('Switch to letter keyboard') : $t('Switch to number keyboard')"
          @click="toggleKeypad"
        >
          <Icon :name="keypad === 'numeric' ? 'keyboard' : 'hash'" />
          <span class="hidden sm:inline">{{ keypad === 'numeric' ? $t('Letters') : $t('Numbers') }}</span>
        </button>
      </div>
    </div>

    <div class="rounded-b-lg border border-t-0 border-outline bg-canvas/60">
      <div v-if="!open" class="p-8 text-center">
        <Icon :name="race.finished_at ? 'flag-checkered' : 'stopwatch'" class="mx-auto size-10 text-muted" />
        <strong class="mt-3 block text-lg">
          {{
            race.finished_at
              ? $t('This race is finished.')
              : !checkpoint
                ? $t('Choose a checkpoint to start timing.')
                : $t('Timing opens when the organizer starts the race.')
          }}
        </strong>
        <p class="mt-1 muted">
          {{
            race.finished_at
              ? $t('Recorded results remain available from the organizer.')
              : $t('You can leave this page open. The list appears as soon as the race starts.')
          }}
        </p>
      </div>

      <template v-else>
        <ul class="grid gap-1.5 p-1.5 sm:p-2" :aria-label="$t('Athletes still to come')">
          <li v-for="p in toCome" :key="p.id">
            <button
              type="button"
              class="grid min-h-18 w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 rounded-md border border-outline bg-surface px-3 py-2.5 text-left transition-colors hover:border-buoy active:bg-raised disabled:opacity-55"
              :disabled="blocked(p) || recorder.saving.value.has(p.id)"
              :aria-label="rowLabel(p)"
              @click="tap(p)"
            >
              <Bib :bib="p.bib_number" />
              <span class="min-w-0">
                <strong class="block truncate text-lg leading-tight">{{ p.name }}</strong>
                <span class="block truncate text-sm muted">{{ subline(p) }}</span>
              </span>
              <span
                class="inline-flex min-w-16 items-center justify-end gap-1 font-semibold"
                :class="blocked(p) ? 'text-error' : 'text-accent'"
              >
                <Icon v-if="recorder.saving.value.has(p.id)" name="spinner" class="animate-spin" />
                {{ stateText(p) }}
              </span>
            </button>
          </li>
        </ul>
        <p v-if="!toCome.length && !done.length" class="p-8 text-center muted">{{ $t('No matching athlete.') }}</p>
        <p v-else-if="!toCome.length" class="px-4 pb-2 pt-4 text-center font-semibold text-success">
          {{ $t('Everyone in this list has a time here.') }}
        </p>

        <div v-if="done.length" class="border-t border-outline">
          <button
            type="button"
            class="flex min-h-12 w-full items-center justify-between gap-3 px-4 text-left font-semibold text-secondary"
            :aria-expanded="showRecorded || !!search"
            @click="showRecorded = !showRecorded"
          >
            <span>{{ $t('Already recorded here (:count)', { count: done.length }) }}</span>
            <Icon :name="showRecorded || search ? 'chevron-up' : 'chevron-down'" />
          </button>
          <ul v-if="showRecorded || search" class="grid gap-1.5 p-1.5 pt-0 sm:p-2 sm:pt-0">
            <li v-for="p in done" :key="p.id">
              <button
                type="button"
                disabled
                class="grid min-h-14 w-full grid-cols-[auto_minmax(0,1fr)_auto] items-center gap-3 rounded-md border border-success/30 bg-success/10 px-3 py-2 text-left"
                :class="{ 'tap-confirmed': lastTapped === p.id }"
                :aria-label="rowLabel(p)"
              >
                <Bib :bib="p.bib_number" small />
                <span class="min-w-0 truncate font-semibold">{{ p.name }}</span>
                <span class="inline-flex items-center gap-1 text-sm font-semibold text-success">
                  <Icon name="check" />
                  {{ $t('Recorded') }}
                </span>
              </button>
            </li>
          </ul>
        </div>
      </template>
    </div>

    <!-- Feedback for the latest tap, with undo. Fixed to the bottom where thumbs are. -->
    <div class="pointer-events-none fixed inset-x-0 bottom-0 z-40 flex justify-center px-3 safe-bottom">
      <div
        v-if="recorder.feedback.value"
        role="status"
        aria-live="polite"
        class="pointer-events-auto mb-3 flex w-full max-w-xl items-center gap-3 rounded-lg px-4 py-3 shadow-2xl"
        :class="
          recorder.feedback.value.type === 'ok'
            ? 'bg-ink text-on-ink'
            : recorder.feedback.value.type === 'offline'
              ? 'bg-bike text-ink'
              : 'bg-error text-white'
        "
      >
        <Icon
          :name="recorder.feedback.value.type === 'ok' ? 'circle-check' : recorder.feedback.value.type === 'offline' ? 'wifi-off' : 'alert'"
          class="size-6"
        />
        <div class="min-w-0 flex-1">
          <p class="font-semibold leading-snug">{{ recorder.feedback.value.message }}</p>
          <p v-if="recorder.feedback.value.tap && recorder.feedback.value.type !== 'error'" class="text-sm tabular opacity-85">
            {{ formatDuration(recorder.feedback.value.tap.elapsed_ms, 2) }}
          </p>
        </div>
        <button
          v-if="canUndo"
          type="button"
          class="inline-flex min-h-11 shrink-0 items-center gap-1.5 rounded-md border border-current/40 px-3 font-semibold"
          @click="recorder.undo(recorder.feedback.value!.tap!)"
        >
          <Icon name="undo" />
          {{ $t('Undo') }}
          <span v-if="undoSecondsLeft !== null" class="tabular text-sm opacity-80">{{ undoSecondsLeft }}s</span>
        </button>
        <button
          v-else
          type="button"
          class="btn-icon !size-10 !min-h-10 !min-w-10 shrink-0 !border-current/30 !bg-transparent !text-current"
          :aria-label="$t('Dismiss')"
          @click="recorder.dismiss()"
        >
          <Icon name="xmark" />
        </button>
      </div>
    </div>

    <dialog
      ref="chooser"
      class="checkpoint-chooser m-0 mt-auto w-full max-w-none bg-transparent p-0 backdrop:bg-ink/60 sm:m-auto sm:max-w-lg"
      :aria-label="$t('Choose a checkpoint')"
      @close="choosing = false"
      @click.self="choosing = false"
    >
      <div v-if="choosing" class="w-full rounded-t-xl bg-surface p-4 text-foreground safe-bottom sm:rounded-xl sm:p-5">
        <div class="mb-3 flex items-center justify-between gap-3">
          <h2 class="text-2xl">{{ $t('Where are you timing?') }}</h2>
          <button type="button" class="btn-icon" :aria-label="$t('Close')" @click="choosing = false"><Icon name="xmark" /></button>
        </div>
        <div class="grid gap-2">
          <button
            v-for="cp in checkpoints"
            :key="cp.id"
            type="button"
            class="checkpoint-band flex min-h-16 items-center justify-between gap-3 rounded-md px-4 text-left"
            :data-discipline="discipline(cp.discipline)"
            :data-kind="cp.kind"
            :aria-current="cp.id === checkpointId ? 'true' : undefined"
            @click="choose(cp.id)"
          >
            <strong class="display text-2xl">{{ $t(cp.name) }}</strong>
            <Icon v-if="cp.id === checkpointId" name="check" class="size-6" />
          </button>
        </div>
      </div>
    </dialog>
  </section>
</template>
