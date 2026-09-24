<?php

namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Enums\TimingStatus;
use App\Exceptions\TimingConflictException;
use App\Exceptions\TimingWarningException;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Services\RaceClockService;
use App\Services\TimingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Inertia\Inertia;

class PublicRaceController extends Controller
{
    public function show(Request $request, string $token)
    {
        $race = $this->race($token);
        $race->load(['checkpoints' => fn ($query) => $query
            ->where('is_active', true)
            ->orderBy('sequence')]);

        $participants = $race->entries()
            ->with([
                'members.athlete',
                'timings' => fn ($query) => $query
                    ->where('status', TimingStatus::Recorded->value)
                    ->select('id', 'entry_id', 'checkpoint_id'),
            ])
            ->orderByRaw('CAST(bib_number AS UNSIGNED), bib_number')
            ->get()
            ->map(fn ($entry) => [
                'id' => $entry->id,
                'status' => $entry->status,
                'bib_number' => $entry->bib_number,
                'type' => $entry->type->value,
                'name' => $entry->displayName(),
                'members' => $entry->members->map(fn ($member) => [
                    'discipline' => $member->discipline->value,
                    'name' => $member->athlete->full_name,
                ])->values()->all(),
                'completed_checkpoint_ids' => $entry->timings->pluck('checkpoint_id')->values()->all(),
            ])->values();

        $recentTimings = $race->timings()
            ->where('status', TimingStatus::Recorded->value)
            ->with(['entry.members.athlete', 'checkpoint:id,name'])
            ->latest('recorded_at')
            ->limit(12)
            ->get()
            ->map(fn ($timing) => [
                'id' => $timing->id,
                'client_uuid' => $timing->client_uuid,
                'elapsed_ms' => $timing->elapsed_ms,
                'recorded_at' => $timing->recorded_at?->toISOString(),
                'entry' => [
                    'id' => $timing->entry_id,
                    'bib_number' => $timing->entry?->bib_number,
                    'display_name' => $timing->entry?->displayName(),
                ],
                'checkpoint' => [
                    'id' => $timing->checkpoint_id,
                    'name' => $timing->checkpoint?->name,
                ],
            ])->values();

        $completedCount = $race->timings()
            ->where('status', TimingStatus::Recorded->value)
            ->whereHas('checkpoint', fn ($query) => $query->where('kind', CheckpointKind::Finish->value))
            ->distinct('entry_id')
            ->count('entry_id');

        $response = Inertia::render('Races/PublicTiming', [
            'race' => [
                'id' => $race->id,
                'name' => $race->name,
                'event_date' => $race->event_date?->format('Y-m-d'),
                'status' => $race->status->value,
                'started_at' => $race->started_at?->toISOString(),
                'finished_at' => $race->finished_at?->toISOString(),
                'settings' => $race->settings,
                'checkpoints' => $race->checkpoints
                    ->where('kind', '!=', CheckpointKind::Start)
                    ->values()
                    ->map(fn ($checkpoint) => [
                        'id' => $checkpoint->id,
                        'race_id' => $checkpoint->race_id,
                        'name' => $checkpoint->name,
                        'code' => $checkpoint->code,
                        'sequence' => $checkpoint->sequence,
                        'discipline' => $checkpoint->discipline?->value,
                        'kind' => $checkpoint->kind->value,
                        'distance_km' => $checkpoint->distance_km,
                        'is_required' => $checkpoint->is_required,
                        'is_active' => $checkpoint->is_active,
                    ])->all(),
                'results_url' => $race->results_published_at && $race->public_results_token
                    ? route('results.public', $race->public_results_token, false)
                    : null,
            ],
            'token' => $token,
            'participants' => $participants,
            'recentTimings' => $recentTimings,
            'completedCount' => $completedCount,
            'serverNow' => now('UTC')->toISOString(),
        ])->toResponse($request);

        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function record(
        Request $request,
        string $token,
        TimingService $service,
        RaceClockService $clock,
    ): JsonResponse {
        $race = $this->race($token);

        $data = $request->validate([
            'entry_id' => ['required', Rule::exists('entries', 'id')->where('race_id', $race->id)],
            'checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where('race_id', $race->id)],
            'client_uuid' => ['required', 'uuid'],
            'observed_at' => ['nullable', 'date'],
            'override_warning' => ['nullable', 'boolean'],
        ]);

        $data['source'] = 'online';

        try {
            $timing = $service->record(
                $race,
                Entry::findOrFail($data['entry_id']),
                Checkpoint::findOrFail($data['checkpoint_id']),
                null,
                $data,
            );

            $timing->load(['entry.members.athlete', 'checkpoint']);
            $label = $timing->entry->displayName()
                .($timing->entry->bib_number !== null ? " (#{$timing->entry->bib_number})" : '');

            $autoFinished = false;
            if ($timing->checkpoint->kind === CheckpointKind::Finish && !$race->finished_at) {
                $hasUnfinishedParticipants = $race->entries()
                    ->where('status', 'registered')
                    ->whereDoesntHave('timings', fn ($query) => $query
                        ->where('status', TimingStatus::Recorded->value)
                        ->whereHas('checkpoint', fn ($checkpointQuery) => $checkpointQuery
                            ->where('kind', CheckpointKind::Finish->value)))
                    ->exists();

                if (!$hasUnfinishedParticipants) {
                    $clock->finish($race->fresh());
                    $autoFinished = true;
                }
            }

            return response()->json([
                'timing' => [
                    'id' => $timing->id,
                    'client_uuid' => $timing->client_uuid,
                    'elapsed_ms' => $timing->elapsed_ms,
                    'recorded_at' => $timing->recorded_at?->toISOString(),
                    'entry' => [
                        'id' => $timing->entry_id,
                        'bib_number' => $timing->entry->bib_number,
                        'display_name' => $timing->entry->displayName(),
                    ],
                    'checkpoint' => [
                        'id' => $timing->checkpoint_id,
                        'name' => $timing->checkpoint->name,
                    ],
                ],
                'auto_finished' => $autoFinished,
                'message' => __(':label recorded at :checkpoint.', ['label'=>$label, 'checkpoint'=>__($timing->checkpoint->name)])
                    .($autoFinished ? ' '.__('All active participants are finished, so the race was finished automatically.') : ''),
            ]);
        } catch (TimingWarningException $e) {
            return response()->json([
                'warning' => true,
                'message' => $e->getMessage(),
                ...$e->context,
            ], 409);
        } catch (TimingConflictException $e) {
            return response()->json([
                'conflict' => true,
                'message' => $e->getMessage(),
            ], 409);
        }
    }

    private function race(string $token): Race
    {
        return Race::query()
            ->where('public_timing_token', $token)
            ->firstOrFail();
    }
}
