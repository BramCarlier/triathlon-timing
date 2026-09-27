<?php
namespace App\Services;

use App\Support\RaceBroadcast;

use App\Enums\RaceStatus;
use App\Enums\CheckpointKind;
use App\Enums\TimingStatus;
use App\Models\EntryChange;
use App\Models\User;
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
                throw ValidationException::withMessages(['race' => __('This race has already been started.')]);
            }
            $readiness = $this->readiness->for($locked);
            if (!$readiness['ready_to_start']) {
                $missing = collect($readiness['checks'])
                    ->where('required', true)
                    ->where('ready', false)
                    ->pluck('detail')
                    ->implode(' ');
                throw ValidationException::withMessages(['race' => $missing ?: __('Finish the required race setup before starting.')]);
            }
            $locked->forceFill(['started_at' => now('UTC'), 'status' => RaceStatus::Running])->save();
            return $locked->fresh();
        });
        RaceBroadcast::dispatch(new RaceStarted($race));
        return $race;
    }

    public function finish(Race $race, ?User $operator = null): Race
    {
        $race = DB::transaction(function () use ($race, $operator) {
            $locked = Race::query()->lockForUpdate()->findOrFail($race->id);
            if (!$locked->started_at) throw ValidationException::withMessages(['race' => __('The race has not started.')]);
            if ($locked->finished_at) return $locked;
            // Serialize against timing uploads and participant edits before deciding who finished.
            $entries = $locked->entries()->where('status', 'registered')->orderBy('id')->lockForUpdate()->get();
            $finishedIds = $locked->timings()
                ->where('status', TimingStatus::Recorded->value)
                ->whereHas('checkpoint', fn ($query) => $query->where('kind', CheckpointKind::Finish->value))
                ->pluck('entry_id')->flip();
            foreach ($entries as $entry) {
                if ($finishedIds->has($entry->id)) continue;
                $entry->update(['status' => 'dnf', 'metadata' => [...($entry->metadata ?? []), 'automatic_dnf' => true]]);
                EntryChange::create([
                    'entry_id' => $entry->id, 'user_id' => $operator?->id,
                    'before' => ['entry' => ['status' => 'registered']],
                    'after' => ['entry' => ['status' => 'dnf']],
                    'reason' => 'Marked DNF automatically: race finished without a finish time.',
                ]);
            }
            $locked->forceFill(['finished_at' => now('UTC'), 'status' => RaceStatus::Finished])->save();
            return $locked->fresh();
        });
        RaceBroadcast::dispatch(new RaceFinished($race));
        return $race;
    }
}
