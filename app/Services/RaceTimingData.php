<?php

namespace App\Services;

use App\Enums\CheckpointKind;
use App\Enums\TimingStatus;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Models\TimingRecord;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * The read models shared by every timing screen: the public race-day link,
 * the Organizer workspace and the authenticated timing station.
 */
class RaceTimingData
{
    /** @return Collection<int, array<string, mixed>> */
    public function participants(Race $race): Collection
    {
        return $race->entries()
            ->with([
                'members.athlete',
                'timings' => fn ($query) => $query
                    ->where('status', TimingStatus::Recorded->value)
                    ->select('id', 'entry_id', 'checkpoint_id'),
            ])
            ->orderByBib()
            ->get()
            ->map(fn (Entry $entry) => $this->participant($entry))
            ->values();
    }

    /** @return array<string, mixed> */
    public function participant(Entry $entry): array
    {
        return [
            'id' => $entry->id,
            'status' => $entry->status->value,
            'bib_number' => $entry->bib_number,
            'type' => $entry->type->value,
            'name' => $entry->displayName(),
            'category' => $entry->category,
            'members' => $entry->members->map(fn ($member) => [
                'discipline' => $member->discipline->value,
                'name' => $member->athlete->full_name,
            ])->values()->all(),
            'completed_checkpoint_ids' => $entry->timings->pluck('checkpoint_id')->values()->all(),
        ];
    }

    /**
     * @param  null|callable(Builder): void  $scope
     * @return Collection<int, array<string, mixed>>
     */
    public function recentTimings(Race $race, int $limit = 12, ?callable $scope = null): Collection
    {
        $query = $race->timings()
            ->where('status', TimingStatus::Recorded->value)
            ->with(['entry.members.athlete', 'checkpoint:id,name', 'operator:id,name'])
            ->latest('recorded_at')
            ->limit($limit);

        if ($scope) {
            $scope($query);
        }

        return $query->get()->map(fn (TimingRecord $timing) => $this->timing($timing))->values();
    }

    /** @return array<string, mixed> */
    public function timing(TimingRecord $timing): array
    {
        return [
            'id' => $timing->id,
            'client_uuid' => $timing->client_uuid,
            'elapsed_ms' => $timing->elapsed_ms,
            'recorded_at' => $timing->recorded_at?->toISOString(),
            'source' => $timing->source?->value,
            'operator' => $timing->operator?->name,
            'entry' => [
                'id' => $timing->entry_id,
                'bib_number' => $timing->entry?->bib_number,
                'display_name' => $timing->entry?->displayName(),
            ],
            'checkpoint' => [
                'id' => $timing->checkpoint_id,
                'name' => $timing->checkpoint?->name,
            ],
        ];
    }

    public function finishedCount(Race $race): int
    {
        return $race->timings()
            ->where('status', TimingStatus::Recorded->value)
            ->whereHas('checkpoint', fn ($query) => $query->where('kind', CheckpointKind::Finish->value))
            ->distinct('entry_id')
            ->count('entry_id');
    }

    /** @return array<string, mixed> */
    public function checkpoint(Checkpoint $checkpoint): array
    {
        return [
            'id' => $checkpoint->id,
            'race_id' => $checkpoint->race_id,
            'name' => $checkpoint->name,
            'code' => $checkpoint->code,
            'sequence' => $checkpoint->sequence,
            'discipline' => $checkpoint->discipline?->value,
            'kind' => $checkpoint->kind->value,
            'distance_km' => $checkpoint->distance_km,
            'is_required' => $checkpoint->is_required,
            'is_active' => $checkpoint->is_active,
        ];
    }
}
