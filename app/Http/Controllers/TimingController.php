<?php

namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Exceptions\TimingConflictException;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Models\TimingRecord;
use App\Services\RaceTimingData;
use App\Services\TimingService;
use App\Support\Like;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;

class TimingController extends Controller
{
    public function selectCheckpoint(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless($request->user()->isAdmin(), 403, __('Only the Organizer can switch checkpoints.'));
        $data = $request->validate(['checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where('race_id', $race->id)]]);
        $checkpoint = Checkpoint::findOrFail($data['checkpoint_id']);
        abort_if(! $checkpoint->is_active, 422, __('This checkpoint is inactive.'));
        abort_if($checkpoint->kind === CheckpointKind::Start, 422, __('The race start is controlled from Race Control.'));
        $request->session()->put("checkpoint.{$race->id}", $checkpoint->id);

        return redirect()->route('races.station', $race)->with('success', __('Checkpoint selected: :checkpoint', ['checkpoint' => __($checkpoint->name)]));
    }

    public function station(Request $request, Race $race, RaceTimingData $data): Response
    {
        Gate::authorize('manage-race', $race);
        $user = $request->user();

        if ($user->isAdmin()) {
            $selectedId = $request->session()->get("checkpoint.{$race->id}");
            $checkpoint = $selectedId ? $race->checkpoints()->whereKey($selectedId)->where('is_active', true)->first() : null;
            $checkpoints = $race->checkpoints()->where('is_active', true)->where('kind', '!=', CheckpointKind::Start->value)->orderBy('sequence')->get();
        } else {
            $assignment = $race->checkpointAssignments()->where('user_id', $user->id)->first();
            $checkpoint = $assignment
                ? $race->checkpoints()->whereKey($assignment->checkpoint_id)->where('is_active', true)->where('kind', '!=', CheckpointKind::Start->value)->first()
                : null;
            $checkpoints = $checkpoint ? collect([$checkpoint]) : collect();
        }

        return Inertia::render('Checkpoints/Station', [
            'race' => $race,
            'checkpoint' => $checkpoint ? $data->checkpoint($checkpoint) : null,
            'checkpoints' => $checkpoints->map(fn (Checkpoint $cp) => $data->checkpoint($cp))->values(),
            'recentTimings' => $checkpoint
                ? $data->recentTimings($race, 10, fn ($query) => $query->where('checkpoint_id', $checkpoint->id)->where('operator_id', $user->id))
                : [],
            'participants' => $data->participants($race),
            'serverNow' => now('UTC')->toISOString(),
        ]);
    }

    public function search(Request $request, Race $race, RaceTimingData $data): JsonResponse
    {
        Gate::authorize('manage-race', $race);
        $term = trim((string) $request->query('q'));
        $pattern = Like::contains($term);

        $entries = $race->entries()
            ->with(['members.athlete', 'timings' => fn ($q) => $q->where('status', 'recorded')->select('id', 'entry_id', 'checkpoint_id')])
            ->when($term !== '', fn ($q) => $q->where(fn ($query) => $query
                ->where('bib_number', 'like', $pattern)
                ->orWhere('team_name', 'like', $pattern)
                ->orWhereHas('members.athlete', fn ($a) => $a->where('first_name', 'like', $pattern)->orWhere('last_name', 'like', $pattern))))
            ->orderByBib()
            ->limit(30)
            ->get();

        return response()->json($entries->map(fn (Entry $entry) => $data->participant($entry))->values());
    }

    public function record(Request $request, Race $race, TimingService $service, RaceTimingData $data): JsonResponse
    {
        Gate::authorize('manage-race', $race);
        $input = $request->validate([
            'operator_id' => ['required_if:source,offline', 'integer', Rule::in([$request->user()->id])],
            'entry_id' => ['required', Rule::exists('entries', 'id')->where('race_id', $race->id)],
            'checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where('race_id', $race->id)],
            'client_uuid' => ['required', 'uuid'],
            'observed_at' => ['nullable', 'date'],
            'source' => ['required', Rule::in(['online', 'offline'])],
            'workspace' => ['nullable', 'boolean'],
            'override_warning' => ['nullable', 'boolean'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if (! $request->user()->isAdmin()) {
            $assignedCheckpointId = $race->checkpointAssignments()->where('user_id', $request->user()->id)->value('checkpoint_id');
            abort_unless((int) $assignedCheckpointId === (int) $input['checkpoint_id'], 403, __('You can only record timings at your assigned checkpoint.'));
        }

        try {
            $timing = $service->record($race, Entry::findOrFail($input['entry_id']), Checkpoint::findOrFail($input['checkpoint_id']), $request->user(), $input);
            $timing->load(['entry.members.athlete', 'checkpoint', 'operator']);
            $autoFinished = $service->finishIfComplete($race, $timing, $request->user());
            $label = $timing->entry->displayName().($timing->entry->bib_number !== null ? " (#{$timing->entry->bib_number})" : '');

            return response()->json([
                'timing' => $data->timing($timing),
                'auto_finished' => $autoFinished,
                'message' => __(':label recorded at :checkpoint.', ['label' => $label, 'checkpoint' => __($timing->checkpoint->name)])
                    .($autoFinished ? ' '.__('All active participants are finished, so the race was finished automatically.') : ''),
            ]);
        } catch (TimingConflictException $e) {
            return response()->json(['conflict' => true, 'message' => $e->getMessage()], 409);
        }
    }

    public function correct(Request $request, Race $race, TimingService $service): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        $data = $request->validate([
            'entry_id' => ['required', Rule::exists('entries', 'id')->where('race_id', $race->id)],
            'checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where('race_id', $race->id)],
            'elapsed_ms' => ['required', 'integer', 'min:0', 'max:604800000'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);
        $service->correct($race, Entry::findOrFail($data['entry_id']), Checkpoint::findOrFail($data['checkpoint_id']), $request->user(), (int) $data['elapsed_ms'], $data['notes'] ?? null);

        return back()->with('success', __('Checkpoint timing corrected. The previous timing, if any, remains in the audit trail as voided.'));
    }

    public function void(Request $request, Race $race, TimingRecord $timing, TimingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless($timing->race_id === $race->id, 404);
        if (! $request->user()->isAdmin()) {
            abort_unless($timing->operator_id === $request->user()->id, 403);
        }
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:1000']]);
        $service->void($timing, $request->user(), $data['reason'] ?? null);

        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', __('Time registration deleted.'));
    }
}
