<?php
namespace App\Services;

use App\Support\RaceBroadcast;

use App\Enums\RaceStatus;
use App\Events\RaceFinished;
use App\Events\RaceStarted;
use App\Models\Race;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RaceClockService
{
    public function __construct(private readonly RaceReadinessService $readiness) {}

    public function start(Race $race): Race
    {
        $race = DB::transaction(function () use ($race) {
            $locked = Race::query()->lockForUpdate()->findOrFail($race->id);
            if ($locked->started_at) {
                throw ValidationException::withMessages(['race' => 'This race has already been started.']);
            }
            $readiness = $this->readiness->for($locked);
            if (!$readiness['ready_to_start']) {
                $missing = collect($readiness['checks'])
                    ->where('required', true)
                    ->where('ready', false)
                    ->pluck('detail')
                    ->implode(' ');
                throw ValidationException::withMessages(['race' => $missing ?: 'Finish the required race setup before starting.']);
            }
            $locked->forceFill(['started_at' => now('UTC'), 'status' => RaceStatus::Running])->save();
            return $locked->fresh();
        });
        RaceBroadcast::dispatch(new RaceStarted($race));
        return $race;
    }

    public function finish(Race $race): Race
    {
        $race = DB::transaction(function () use ($race) {
            $locked = Race::query()->lockForUpdate()->findOrFail($race->id);
            if (!$locked->started_at) throw ValidationException::withMessages(['race' => 'The race has not started.']);
            if ($locked->finished_at) return $locked;
            $locked->forceFill(['finished_at' => now('UTC'), 'status' => RaceStatus::Finished])->save();
            return $locked->fresh();
        });
        RaceBroadcast::dispatch(new RaceFinished($race));
        return $race;
    }
}
