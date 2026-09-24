<?php
namespace App\Http\Controllers;

use App\Enums\CheckpointKind;
use App\Enums\Discipline;
use App\Models\Checkpoint;
use App\Models\Race;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CheckpointController extends Controller
{
    public function store(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless(request()->user()->isAdmin(), 403);
        $this->ensureSetupUnlocked($race);
        if (!$request->filled('code')) {
            $base = substr(strtoupper(\Illuminate\Support\Str::slug((string) $request->input('name'), '_')), 0, 36) ?: 'CHECKPOINT';
            $code = $base;
            for ($suffix = 2; $race->checkpoints()->where('code', $code)->exists(); $suffix++) $code = $base.'_'.$suffix;
            $request->merge(['code' => $code]);
        }
        $data = $this->validated($request, $race);
        $race->checkpoints()->create($data);
        return back()->with('success', __('Checkpoint added.'));
    }

    public function update(Request $request, Race $race, Checkpoint $checkpoint): RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($checkpoint->race_id === $race->id, 404);
        abort_unless(request()->user()->isAdmin(), 403);
        $this->ensureSetupUnlocked($race);
        $checkpoint->update($this->validated($request, $race, $checkpoint));
        return back()->with('success', __('Checkpoint updated.'));
    }

    public function destroy(Race $race, Checkpoint $checkpoint): RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($checkpoint->race_id === $race->id, 404);
        abort_unless(request()->user()->isAdmin(), 403);
        $this->ensureSetupUnlocked($race);
        if($checkpoint->kind===CheckpointKind::Start)throw \Illuminate\Validation\ValidationException::withMessages(['checkpoint'=>__('The race start checkpoint cannot be deleted.')]);
        if($checkpoint->timings()->exists())throw \Illuminate\Validation\ValidationException::withMessages(['checkpoint'=>__('A checkpoint with timings cannot be deleted. Disable it instead.')]);
        $checkpoint->delete();
        return back()->with('success', __('Checkpoint deleted.'));
    }

    private function ensureSetupUnlocked(Race $race): void
    {
        if ($race->started_at) {
            throw ValidationException::withMessages([
                'race' => 'Checkpoints are locked after the race starts.',
            ]);
        }
    }

    private function validated(Request $request, Race $race, ?Checkpoint $checkpoint = null): array
    {
        return $request->validate([
            'name' => ['required','string','max:255'],
            'code' => ['required','string','max:48', Rule::unique('checkpoints')->where('race_id', $race->id)->ignore($checkpoint?->id)],
            'sequence' => ['required','integer','min:0','max:65535', Rule::unique('checkpoints')->where('race_id', $race->id)->ignore($checkpoint?->id)],
            'discipline' => ['nullable', Rule::enum(Discipline::class)],
            'kind' => ['required', Rule::enum(CheckpointKind::class)],
            'distance_km' => ['nullable','numeric','min:0'],
            'is_required' => ['required','boolean'],
            'is_active' => ['required','boolean'],
        ]);
    }
}
