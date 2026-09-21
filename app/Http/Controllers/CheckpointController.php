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

class CheckpointController extends Controller
{
    public function store(Request $request, Race $race): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        $data = $this->validated($request, $race);
        $race->checkpoints()->create($data);
        return back()->with('success', 'Checkpoint added.');
    }

    public function update(Request $request, Race $race, Checkpoint $checkpoint): RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($checkpoint->race_id === $race->id, 404);
        $checkpoint->update($this->validated($request, $race, $checkpoint));
        return back()->with('success', 'Checkpoint updated.');
    }

    public function destroy(Race $race, Checkpoint $checkpoint): RedirectResponse
    {
        Gate::authorize('manage-race', $race); abort_unless($checkpoint->race_id === $race->id, 404);
        abort_if($checkpoint->timings()->exists(), 422, 'A checkpoint with timings cannot be deleted. Disable it instead.');
        $checkpoint->delete();
        return back()->with('success', 'Checkpoint deleted.');
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
