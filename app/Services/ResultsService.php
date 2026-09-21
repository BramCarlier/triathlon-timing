<?php
namespace App\Services;

use App\Enums\CheckpointKind;
use App\Enums\TimingStatus;
use App\Models\Race;

class ResultsService
{
    public function rows(Race $race): array
    {
        $checkpoints = $race->checkpoints()->where('kind', '!=', CheckpointKind::Start->value)->get();
        $entries = $race->entries()->with(['members.athlete', 'timings' => fn ($q) => $q->where('status', TimingStatus::Recorded->value)->with('checkpoint')])->get();

        return $entries->map(function ($entry) use ($checkpoints) {
            $byCheckpoint = $entry->timings->keyBy('checkpoint_id');
            $previous = 0;
            $splits = [];
            foreach ($checkpoints as $checkpoint) {
                $timing = $byCheckpoint->get($checkpoint->id);
                if (!$timing) {
                    $splits[] = ['checkpoint' => $checkpoint->name, 'elapsed_ms' => null, 'split_ms' => null];
                    continue;
                }
                $splits[] = ['checkpoint' => $checkpoint->name, 'elapsed_ms' => $timing->elapsed_ms, 'split_ms' => $timing->elapsed_ms - $previous];
                $previous = $timing->elapsed_ms;
            }
            $finish = $entry->timings->filter(fn ($t) => $t->checkpoint?->kind === CheckpointKind::Finish)->sortByDesc('elapsed_ms')->first();
            return [
                'id' => $entry->id,
                'bib_number' => $entry->bib_number,
                'type' => $entry->type->value,
                'name' => $entry->displayName(),
                'category' => $entry->category,
                'members' => $entry->members->map(fn ($member) => ['discipline' => $member->discipline->value, 'name' => $member->athlete->full_name])->values()->all(),
                'splits' => $splits,
                'total_ms' => $finish?->elapsed_ms,
                'finished' => (bool) $finish,
            ];
        })->sortBy(fn ($row) => $row['total_ms'] ?? PHP_INT_MAX)->values()->all();
    }
}
