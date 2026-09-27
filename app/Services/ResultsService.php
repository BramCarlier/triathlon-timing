<?php
namespace App\Services;

use App\Enums\CheckpointKind;
use App\Enums\TimingStatus;
use App\Models\Race;

class ResultsService
{
    public function filtered(Race $race,array $filters=[]): array
    {
        $rows=collect($this->rows($race))->filter(fn($row)=>
            (empty($filters['type'])||$row['type']===$filters['type'])&&
            (empty($filters['category'])||$row['category']===$filters['category'])&&
            (empty($filters['status'])||$row['result_status']===$filters['status'])
        )->values()->all();
        $previous=null;$place=null;$leader=null;
        foreach($rows as $index=>&$row){
            if(!$row['finished']){$row['place']=null;$row['gap_ms']=null;continue;}
            $leader??=$row['total_ms'];$row['gap_ms']=$row['total_ms']-$leader;
            if($row['total_ms']!==$previous)$place=$index+1;
            $row['place']=$place;$previous=$row['total_ms'];
        }
        unset($row);
        return $this->withCheckpointPlaces($rows);
    }

    /** Provisional places use course progress first, then elapsed time at that checkpoint. */
    public function live(Race $race): array
    {
        $rows = collect($this->rows($race, activeOnly: true))->map(function ($row) use ($race) {
            $latestIndex = null;
            foreach ($row['splits'] as $index => $split) {
                if ($split['elapsed_ms'] !== null) $latestIndex = $index;
            }
            $latest = $latestIndex !== null ? $row['splits'][$latestIndex] : null;
            $row['latest_checkpoint'] = $latest['checkpoint'] ?? null;
            $row['latest_elapsed_ms'] = $latest['elapsed_ms'] ?? null;
            $row['place'] = null;
            $row['result_status'] = $row['status'] !== 'registered'
                ? strtoupper($row['status'])
                : ($row['finished'] ? 'FINISHED' : ($race->started_at ? 'IN PROGRESS' : 'Awaiting start'));
            $row['_rankable'] = $row['status'] === 'registered' && $latest !== null;
            $row['_sort'] = [
                $row['status'] !== 'registered' ? 3 : ($row['finished'] ? 0 : ($latest ? 1 : 2)),
                $row['finished'] ? 0 : -($latestIndex ?? -1),
                $row['finished'] ? $row['total_ms'] : ($row['latest_elapsed_ms'] ?? PHP_INT_MAX),
            ];
            return $row;
        })->sort(fn ($a, $b) => ($a['_sort'] <=> $b['_sort']) ?: ($a['id'] <=> $b['id']))->values();

        $previous = null;
        $place = null;
        return $this->withCheckpointPlaces($rows->map(function ($row, $index) use (&$previous, &$place) {
            if ($row['_rankable']) {
                if ($row['_sort'] !== $previous) $place = $index + 1;
                $row['place'] = $place;
                $previous = $row['_sort'];
            }
            unset($row['_rankable'], $row['_sort']);
            return $row;
        })->all());
    }

    public function rows(Race $race, bool $activeOnly = false): array
    {
        $checkpoints = $race->checkpoints()->where('kind', '!=', CheckpointKind::Start->value)->when($activeOnly, fn ($q) => $q->where('is_active', true))->get();
        $entries = $race->entries()->with(['members.athlete', 'timings' => fn ($q) => $q->where('status', TimingStatus::Recorded->value)->when($activeOnly, fn ($q) => $q->whereHas('checkpoint', fn ($q) => $q->where('is_active', true)))->with('checkpoint')])->get();

        return $entries->map(function ($entry) use ($checkpoints) {
            $byCheckpoint = $entry->timings->keyBy('checkpoint_id');
            $previous = 0;
            $splits = [];
            foreach ($checkpoints as $checkpoint) {
                $timing = $byCheckpoint->get($checkpoint->id);
                if (!$timing) {
                    $splits[] = ['checkpoint_id' => $checkpoint->id, 'checkpoint' => $checkpoint->name, 'elapsed_ms' => null, 'split_ms' => null];
                    continue;
                }
                $splits[] = ['checkpoint_id' => $checkpoint->id, 'checkpoint' => $checkpoint->name, 'elapsed_ms' => $timing->elapsed_ms, 'split_ms' => $timing->elapsed_ms - $previous];
                $previous = $timing->elapsed_ms;
            }
            $finish = $entry->timings->filter(fn ($t) => $t->checkpoint?->kind === CheckpointKind::Finish)->sortByDesc('elapsed_ms')->first();
            return [
                'id' => $entry->id,
                'status' => $entry->status,
                'result_status' => $entry->status !== 'registered' ? strtoupper($entry->status) : ($finish ? 'FINISHED' : 'IN PROGRESS'),
                'bib_number' => $entry->bib_number,
                'type' => $entry->type->value,
                'name' => $entry->displayName(),
                'category' => $entry->category,
                'members' => $entry->members->map(fn ($member) => ['discipline' => $member->discipline->value, 'name' => $member->athlete->full_name])->values()->all(),
                'splits' => $splits,
                'total_ms' => $finish?->elapsed_ms,
                'finished' => (bool) $finish && $entry->status === 'registered',
            ];
        })->sortBy(fn ($row) => $row['finished'] ? ($row['total_ms'] ?? PHP_INT_MAX) : PHP_INT_MAX)->values()->all();
    }

    /** Rank each checkpoint independently within the displayed result group. */
    private function withCheckpointPlaces(array $rows): array
    {
        $times = [];
        foreach ($rows as $row) {
            if ($row['status'] !== 'registered') continue;
            foreach ($row['splits'] as $split) {
                if ($split['elapsed_ms'] !== null) {
                    $times[$split['checkpoint_id']][$row['id']] = $split['elapsed_ms'];
                }
            }
        }

        $places = [];
        foreach ($times as $checkpointId => $checkpointTimes) {
            asort($checkpointTimes, SORT_NUMERIC);
            $position = 0;
            $previous = null;
            $place = null;
            foreach ($checkpointTimes as $entryId => $elapsedMs) {
                $position++;
                if ($elapsedMs !== $previous) $place = $position;
                $places[$checkpointId][$entryId] = $place;
                $previous = $elapsedMs;
            }
        }

        foreach ($rows as &$row) {
            foreach ($row['splits'] as &$split) {
                $split['place'] = $places[$split['checkpoint_id']][$row['id']] ?? null;
            }
            unset($split);
        }
        unset($row);
        return $rows;
    }

}
