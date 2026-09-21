<?php
namespace App\Http\Controllers;

use App\Enums\TimingStatus;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class AthleteDashboardController extends Controller
{
    public function __invoke(Request $request): Response
    {
        $athlete = $request->user()->athlete;
        abort_unless($athlete, 403, 'This account is not linked to an athlete profile.');
        $memberships = $athlete->memberships()->with(['entry.race.checkpoints', 'entry.members.athlete', 'entry.timings' => fn ($q) => $q->where('status', TimingStatus::Recorded->value)->with('checkpoint')])->get();
        $entries = $memberships->pluck('entry')->filter(fn ($entry) => $entry?->race !== null)->unique('id')->values()->map(function ($entry) {
            return ['id' => $entry->id, 'bib_number' => $entry->bib_number, 'type' => $entry->type->value, 'team_name' => $entry->team_name, 'race' => $entry->race, 'members' => $entry->members, 'timings' => $entry->timings->sortBy(fn ($t) => $t->checkpoint->sequence)->values()];
        });
        return Inertia::render('Athlete/Dashboard', ['athlete' => $athlete, 'entries' => $entries, 'serverNow' => now('UTC')->toISOString()]);
    }
}
