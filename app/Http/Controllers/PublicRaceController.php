<?php

namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Enums\TimingStatus;
use App\Exceptions\TimingConflictException;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Models\TimingRecord;
use App\Services\RaceTimingData;
use App\Services\ResultsService;
use App\Services\TimingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Symfony\Component\HttpFoundation\Response;

/**
 * The account-free race-day timing link. Anyone holding the unguessable token can
 * record a crossing for this race. They cannot read or change anything else.
 */
class PublicRaceController extends Controller
{
    /** A tap saved on a device may be uploaded this long after it was observed. */
    public const MAX_QUEUED_AGE_MS = 30 * 60 * 1000;

    /** A device may undo its own tap for this long. */
    public const UNDO_WINDOW_SECONDS = 60;

    public function show(Request $request, string $token, RaceTimingData $data): Response
    {
        $race = $this->race($token, requireEnabled: false);
        $race->load(['checkpoints' => fn ($query) => $query->where('is_active', true)->orderBy('sequence')]);

        $response = Inertia::render('Races/PublicTiming', [
            'race' => [
                'id' => $race->id,
                'name' => $race->name,
                'event_date' => $race->event_date?->format('Y-m-d'),
                'status' => $race->status->value,
                'started_at' => $race->started_at?->toISOString(),
                'finished_at' => $race->finished_at?->toISOString(),
                'timing_enabled' => (bool) $race->public_timing_enabled,
                'checkpoints' => $race->checkpoints
                    ->where('kind', '!=', CheckpointKind::Start)
                    ->values()
                    ->map(fn (Checkpoint $checkpoint) => $data->checkpoint($checkpoint))
                    ->all(),
                'results_url' => $race->results_published_at && $race->public_results_token
                    ? route('results.public', $race->public_results_token, false)
                    : null,
            ],
            'token' => $token,
            'participants' => fn () => $race->public_timing_enabled ? $data->participants($race) : [],
            'standings' => fn () => app(ResultsService::class)->live($race),
            // Client UUIDs are omitted: knowing one is what lets a device undo its own tap.
            'recentTimings' => fn () => $race->public_timing_enabled
                ? $data->recentTimings($race)->map(fn (array $timing) => collect($timing)->except(['client_uuid', 'operator', 'source'])->all())
                : [],
            'completedCount' => fn () => $data->finishedCount($race),
            'serverNow' => fn () => now('UTC')->toISOString(),
            'undoWindowSeconds' => self::UNDO_WINDOW_SECONDS,
        ])->toResponse($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function record(Request $request, string $token, TimingService $service, RaceTimingData $data): JsonResponse
    {
        $race = $this->race($token);

        $input = $request->validate([
            'entry_id' => ['required', Rule::exists('entries', 'id')->where('race_id', $race->id)],
            'checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where('race_id', $race->id)],
            'client_uuid' => ['required', 'uuid'],
            'observed_at' => ['nullable', 'date'],
            'source' => ['nullable', Rule::in(['online', 'offline'])],
            'override_warning' => ['nullable', 'boolean'],
        ]);

        $input['source'] ??= 'online';
        // Saved taps keep the time they were observed, but only for a bounded period.
        $input['max_offline_age_ms'] = self::MAX_QUEUED_AGE_MS;

        try {
            $timing = $service->record(
                $race,
                Entry::findOrFail($input['entry_id']),
                Checkpoint::findOrFail($input['checkpoint_id']),
                null,
                $input,
            );
            $timing->load(['entry.members.athlete', 'checkpoint']);
            $autoFinished = $service->finishIfComplete($race, $timing, null);

            return response()->json([
                'timing' => collect($data->timing($timing))->except(['operator', 'source'])->all(),
                'auto_finished' => $autoFinished,
                'undo_until' => $timing->created_at?->addSeconds(self::UNDO_WINDOW_SECONDS)->toISOString(),
                'message' => $this->recordedMessage($timing, $autoFinished),
            ]);
        } catch (TimingConflictException $e) {
            return response()->json(['conflict' => true, 'message' => $e->getMessage()], 409);
        }
    }

    /** Undo a tap from the same device shortly after it was recorded. */
    public function undo(Request $request, string $token, string $clientUuid, TimingService $service): JsonResponse
    {
        $race = $this->race($token);
        $timing = TimingRecord::query()
            ->where('race_id', $race->id)
            ->where('client_uuid', $clientUuid)
            ->whereNull('operator_id')
            ->firstOrFail();

        if ($timing->status === TimingStatus::Voided) {
            return response()->json(['ok' => true]);
        }
        if ($race->finished_at || $timing->created_at->lt(now()->subSeconds(self::UNDO_WINDOW_SECONDS))) {
            return response()->json([
                'conflict' => true,
                'message' => __('This tap can no longer be undone here. Ask the Organizer to correct it.'),
            ], 409);
        }

        $service->void($timing, null, 'Undone from the race-day link');

        return response()->json(['ok' => true, 'message' => __('Tap undone.')]);
    }

    private function recordedMessage(TimingRecord $timing, bool $autoFinished): string
    {
        $label = $timing->entry->displayName()
            .($timing->entry->bib_number !== null ? " (#{$timing->entry->bib_number})" : '');

        return __(':label recorded at :checkpoint.', ['label' => $label, 'checkpoint' => __($timing->checkpoint->name)])
            .($autoFinished ? ' '.__('All active participants are finished, so the race was finished automatically.') : '');
    }

    private function race(string $token, bool $requireEnabled = true): Race
    {
        $race = Race::query()->where('public_timing_token', $token)->firstOrFail();
        abort_if($requireEnabled && ! $race->public_timing_enabled, 403, __('The Organizer has switched off this race-day link.'));

        return $race;
    }
}
