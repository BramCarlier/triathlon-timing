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
        return $rows;
    }

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
}
