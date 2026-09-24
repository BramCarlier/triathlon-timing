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
        abort_unless(request()->user()->isAdmin(), 403);
        $race->load('checkpoints')->loadCount('entries');
        $recent = $race->timings()->where('status', TimingStatus::Recorded->value)->with(['entry.members.athlete', 'checkpoint:id,name', 'operator:id,name'])->latest('recorded_at')->limit(20)->get();
        $presence = OperatorPresence::where('race_id', $race->id)->where('last_seen_at', '>=', now()->subMinutes(5))->with(['user:id,name', 'checkpoint:id,name'])->get();
        $entries = $race->entries()->with(['members.athlete','timings'=>fn($q)=>$q->where('status',TimingStatus::Recorded->value)])->orderByRaw('CAST(bib_number AS UNSIGNED), bib_number')->get()->map(fn ($entry) => ['id' => $entry->id, 'bib_number' => $entry->bib_number, 'name' => $entry->displayName(), 'timings'=>$entry->timings->map(fn($timing)=>['checkpoint_id'=>$timing->checkpoint_id,'elapsed_ms'=>$timing->elapsed_ms])->all()])->values();
        $completed = $race->timings()->where('status', TimingStatus::Recorded->value)->whereHas('checkpoint', fn ($q) => $q->where('kind', 'finish'))->distinct('entry_id')->count('entry_id');
        return Inertia::render('Races/Control', ['race' => $race, 'recentTimings' => $recent, 'presence' => $presence, 'completedCount' => $completed, 'entries' => $entries ]);
    }

    public function start(Race $race, RaceClockService $clock): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless(request()->user()->isAdmin(), 403);
        $clock->start($race);
        return back()->with('success', __('Race clock started.'));
    }

    public function finish(Race $race, RaceClockService $clock): RedirectResponse
    {
        Gate::authorize('manage-race', $race);
        abort_unless(request()->user()->isAdmin(), 403);
        $clock->finish($race);
        return back()->with('success', __('Race marked as finished.'));
    }
}
