<?php
namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Enums\TimingStatus;
use App\Exceptions\TimingConflictException;
use App\Exceptions\TimingWarningException;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Models\TimingRecord;
use App\Services\TimingService;
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
        $data = $request->validate(['checkpoint_id' => ['required', Rule::exists('checkpoints', 'id')->where('race_id', $race->id)]]);
        $checkpoint = Checkpoint::findOrFail($data['checkpoint_id']);
        abort_if(!$checkpoint->is_active, 422, 'This checkpoint is inactive.');
        abort_if($checkpoint->kind === CheckpointKind::Start, 422, 'The race start is controlled from Race Control.');
        $request->session()->put("checkpoint.{$race->id}", $checkpoint->id);
        return redirect()->route('races.station', $race)->with('success', "Checkpoint selected: {$checkpoint->name}");
    }

    public function station(Request $request, Race $race): Response
    {
        Gate::authorize('manage-race', $race);
        $selectedId = $request->session()->get("checkpoint.{$race->id}");
        $checkpoint = $selectedId ? $race->checkpoints()->whereKey($selectedId)->where('is_active', true)->first() : null;
        $checkpoints = $race->checkpoints()->where('is_active', true)->where('kind', '!=', CheckpointKind::Start->value)->orderBy('sequence')->get();
        $recent = $checkpoint ? TimingRecord::where('checkpoint_id', $checkpoint->id)->where('operator_id', $request->user()->id)->where('status', TimingStatus::Recorded->value)->with('entry.members.athlete')->latest('recorded_at')->limit(10)->get() : [];
        $participants = $race->entries()->with(['members.athlete', 'timings' => fn ($q) => $q->where('status', TimingStatus::Recorded->value)->select('id', 'entry_id', 'checkpoint_id')])->get()->map(fn ($entry) => [
            'id' => $entry->id,
            'bib_number' => $entry->bib_number,
            'type' => $entry->type->value,
            'name' => $entry->displayName(),
            'members' => $entry->members->map(fn ($member) => ['discipline' => $member->discipline->value, 'name' => $member->athlete->full_name])->values()->all(),
            'completed_checkpoint_ids' => $entry->timings->pluck('checkpoint_id')->values()->all(),
        ])->values();
        return Inertia::render('Checkpoints/Station', ['race' => $race, 'checkpoint' => $checkpoint, 'checkpoints' => $checkpoints, 'recentTimings' => $recent, 'participants' => $participants, 'serverNow' => now('UTC')->toISOString()]);
    }

    public function search(Request $request, Race $race): JsonResponse
    {
        Gate::authorize('manage-race', $race);
        $term = trim((string) $request->query('q'));
        $entries = $race->entries()->with(['members.athlete', 'timings' => fn ($q) => $q->where('status', TimingStatus::Recorded->value)->select('id','entry_id','checkpoint_id','elapsed_ms')])
            ->when($term, fn ($q) => $q->where(function ($query) use ($term) { $query->where('bib_number', 'like', "%{$term}%")->orWhere('team_name', 'like', "%{$term}%")->orWhereHas('members.athlete', fn ($a) => $a->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%")); }))
            ->orderByRaw('CAST(bib_number AS UNSIGNED), bib_number')->limit(30)->get();
        return response()->json($entries->map(fn ($entry) => ['id' => $entry->id, 'bib_number' => $entry->bib_number, 'type' => $entry->type->value, 'name' => $entry->displayName(), 'members' => $entry->members->map(fn ($m) => ['discipline' => $m->discipline->value, 'name' => $m->athlete->full_name]), 'completed_checkpoint_ids' => $entry->timings->pluck('checkpoint_id')])->values());
    }

    public function record(Request $request, Race $race, TimingService $service): JsonResponse
    {
        Gate::authorize('manage-race', $race);
        $data = $request->validate(['entry_id' => ['required', Rule::exists('entries','id')->where('race_id', $race->id)], 'checkpoint_id' => ['required', Rule::exists('checkpoints','id')->where('race_id', $race->id)], 'client_uuid' => ['required','uuid'], 'observed_at' => ['nullable','date'], 'source' => ['required', Rule::in(['online','offline','manual'])], 'override_warning' => ['nullable','boolean'], 'notes' => ['nullable','string','max:1000']]);
        $selectedId = (int) $request->session()->get("checkpoint.{$race->id}");
        abort_unless($request->user()->isAdmin() || $selectedId === (int) $data['checkpoint_id'], 403, 'Your active checkpoint does not match this timing request.');
        try {
            $timing = $service->record($race, Entry::findOrFail($data['entry_id']), Checkpoint::findOrFail($data['checkpoint_id']), $request->user(), $data);
            $timing->load(['entry.members.athlete', 'checkpoint']);
            $label = $timing->entry->displayName().($timing->entry->bib_number !== null ? " (#{$timing->entry->bib_number})" : '');
            return response()->json(['timing' => $timing, 'message' => "{$label} recorded at {$timing->checkpoint->name}."]);
        } catch (TimingWarningException $e) {
            return response()->json(['warning' => true, 'message' => $e->getMessage(), ...$e->context], 409);
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
        return back()->with('success', 'Checkpoint timing corrected. The previous timing, if any, remains in the audit trail as voided.');
    }

    public function void(Request $request, Race $race, TimingRecord $timing, TimingService $service): JsonResponse|RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($timing->race_id === $race->id, 404);
        if (!$request->user()->isAdmin()) abort_unless($timing->operator_id === $request->user()->id, 403);
        $data = $request->validate(['reason' => ['nullable','string','max:1000']]);
        $service->void($timing, $request->user(), $data['reason'] ?? null);
        return $request->expectsJson() ? response()->json(['ok' => true]) : back()->with('success', 'Timing voided. You can record the participant again.');
    }
}
