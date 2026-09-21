<?php
namespace App\Http\Controllers;

use App\Enums\TimingStatus;
use App\Models\OperatorPresence;
use App\Models\Race;
use App\Services\RaceClockService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class RaceControlController extends Controller
{
    public function show(Race $race): Response
    {
        Gate::authorize('manage-race', $race);
        $race->load('checkpoints')->loadCount('entries');
        $recent = $race->timings()->where('status', TimingStatus::Recorded->value)->with(['entry.members.athlete', 'checkpoint:id,name', 'operator:id,name'])->latest('recorded_at')->limit(20)->get();
        $presence = OperatorPresence::where('race_id', $race->id)->where('last_seen_at', '>=', now()->subMinutes(5))->with(['user:id,name', 'checkpoint:id,name'])->get();
        $entries = $race->entries()->with('members.athlete')->orderByRaw('CAST(bib_number AS UNSIGNED), bib_number')->get()->map(fn ($entry) => ['id' => $entry->id, 'bib_number' => $entry->bib_number, 'name' => $entry->displayName()])->values();
        $completed = $race->timings()->where('status', TimingStatus::Recorded->value)->whereHas('checkpoint', fn ($q) => $q->where('kind', 'finish'))->distinct('entry_id')->count('entry_id');
        return Inertia::render('Races/Control', ['race' => $race, 'recentTimings' => $recent, 'presence' => $presence, 'completedCount' => $completed, 'entries' => $entries, 'serverNow' => now('UTC')->toISOString()]);
    }

    public function start(Race $race, RaceClockService $clock): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        $clock->start($race);
        return back()->with('success', 'Race clock started.');
    }

    public function finish(Race $race, RaceClockService $clock): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        $clock->finish($race);
        return back()->with('success', 'Race marked as finished.');
    }
}
