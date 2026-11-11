<?php

namespace App\Services;

use App\Enums\CheckpointKind;
use App\Enums\EntryStatus;
use App\Enums\TimingStatus;
use App\Models\Race;
use App\Support\ResultsCache;
use Illuminate\Support\Facades\Cache;

class ResultsService
{
    /**
     * Final results for the selected group. Finishers get competition places
     * (equal totals share a place: 1, 1, 3). While the race runs, the remaining
     * registered entries are ordered by course progress and get a provisional place.
     */
    public function filtered(Race $race, array $filters = []): array
    {
        $running = $race->started_at && ! $race->finished_at;

        $rows = collect($this->rows($race))->filter(fn ($row) => (empty($filters['type']) || $row['type'] === $filters['type'])
            && (empty($filters['category']) || $row['category'] === $filters['category'])
            && (empty($filters['status']) || $row['result_status'] === $filters['status'])
        );

        if ($running) {
            $rows = $rows->sort(fn ($a, $b) => ($this->progressKey($a) <=> $this->progressKey($b)) ?: ($a['id'] <=> $b['id']));
        }
        $rows = $rows->values()->all();

        $previous = null;
        $place = null;
        $leader = null;
        $previousProgress = null;
        $provisional = null;
        foreach ($rows as $index => &$row) {
            $row['provisional_place'] = null;
            if ($row['finished']) {
                $leader ??= $row['total_ms'];
                $row['gap_ms'] = $row['total_ms'] - $leader;
                if ($row['total_ms'] !== $previous) {
                    $place = $index + 1;
                }
                $row['place'] = $place;
                $previous = $row['total_ms'];

                continue;
            }

            $row['place'] = null;
            $row['gap_ms'] = null;
            if ($running && $row['status'] === EntryStatus::Registered->value && $this->latestIndex($row) !== null) {
                $key = $this->progressKey($row);
                if ($key !== $previousProgress) {
                    $provisional = $index + 1;
                }
                $row['provisional_place'] = $provisional;
                $previousProgress = $key;
            }
        }
        unset($row);

        return $this->withCheckpointPlaces($rows);
    }

    /** Provisional places use course progress first, then elapsed time at that checkpoint. */
    public function live(Race $race): array
    {
        return Cache::remember(ResultsCache::key($race, 'live'), now()->addMinutes(10), function () use ($race) {
            $rows = collect($this->rows($race, activeOnly: true))->map(function ($row) use ($race) {
                $latestIndex = $this->latestIndex($row);
                $latest = $latestIndex !== null ? $row['splits'][$latestIndex] : null;
                $row['latest_checkpoint'] = $latest['checkpoint'] ?? null;
                $row['latest_elapsed_ms'] = $latest['elapsed_ms'] ?? null;
                $row['place'] = null;
                $row['result_status'] = $row['status'] !== EntryStatus::Registered->value
                    ? strtoupper($row['status'])
                    : ($row['finished'] ? 'FINISHED' : ($race->started_at ? 'IN PROGRESS' : 'Awaiting start'));
                $row['_rankable'] = $row['status'] === EntryStatus::Registered->value && $latest !== null;
                $row['_sort'] = $this->progressKey($row);

                return $row;
            })->sort(fn ($a, $b) => ($a['_sort'] <=> $b['_sort']) ?: ($a['id'] <=> $b['id']))->values();

            $previous = null;
            $place = null;

            return $this->withCheckpointPlaces($rows->map(function ($row, $index) use (&$previous, &$place) {
                if ($row['_rankable']) {
                    if ($row['_sort'] !== $previous) {
                        $place = $index + 1;
                    }
                    $row['place'] = $place;
                    $previous = $row['_sort'];
                }
                unset($row['_rankable'], $row['_sort']);

                return $row;
            })->all());
        });
    }

    public function rows(Race $race, bool $activeOnly = false): array
    {
        $checkpoints = $race->checkpoints()
            ->where('kind', '!=', CheckpointKind::Start->value)
            ->when($activeOnly, fn ($q) => $q->where('is_active', true))
            ->get();

        $entries = $race->entries()->with([
            'members.athlete',
            'timings' => fn ($q) => $q->where('status', TimingStatus::Recorded->value)
                ->when($activeOnly, fn ($q) => $q->whereHas('checkpoint', fn ($q) => $q->where('is_active', true)))
                ->with('checkpoint'),
        ])->get();

        return $entries->map(function ($entry) use ($checkpoints) {
            $byCheckpoint = $entry->timings->keyBy('checkpoint_id');
            $previous = 0;
            $splits = [];
            foreach ($checkpoints as $checkpoint) {
                $timing = $byCheckpoint->get($checkpoint->id);
                if (! $timing) {
                    $splits[] = ['checkpoint_id' => $checkpoint->id, 'checkpoint' => $checkpoint->name, 'elapsed_ms' => null, 'split_ms' => null];

                    continue;
                }
                $splits[] = ['checkpoint_id' => $checkpoint->id, 'checkpoint' => $checkpoint->name, 'elapsed_ms' => $timing->elapsed_ms, 'split_ms' => $timing->elapsed_ms - $previous];
                $previous = $timing->elapsed_ms;
            }

            $finish = $entry->timings
                ->filter(fn ($t) => $t->checkpoint?->kind === CheckpointKind::Finish)
                ->sortByDesc('elapsed_ms')
                ->first();
            $registered = $entry->status === EntryStatus::Registered;

            return [
                'id' => $entry->id,
                'status' => $entry->status->value,
                'result_status' => ! $registered ? $entry->status->resultLabel() : ($finish ? 'FINISHED' : 'IN PROGRESS'),
                'bib_number' => $entry->bib_number,
                'type' => $entry->type->value,
                'name' => $entry->displayName(),
                'category' => $entry->category,
                'members' => $entry->members->map(fn ($member) => ['discipline' => $member->discipline->value, 'name' => $member->athlete->full_name])->values()->all(),
                'splits' => $splits,
                'total_ms' => $finish?->elapsed_ms,
                'finished' => (bool) $finish && $registered,
            ];
        })->sortBy(fn ($row) => $row['finished'] ? ($row['total_ms'] ?? PHP_INT_MAX) : PHP_INT_MAX)->values()->all();
    }

    private function latestIndex(array $row): ?int
    {
        $latest = null;
        foreach ($row['splits'] as $index => $split) {
            if ($split['elapsed_ms'] !== null) {
                $latest = $index;
            }
        }

        return $latest;
    }

    /** Sort key: finished first by total, then furthest checkpoint, then fastest there. */
    private function progressKey(array $row): array
    {
        $latestIndex = $this->latestIndex($row);
        $latestElapsed = $latestIndex !== null ? $row['splits'][$latestIndex]['elapsed_ms'] : null;

        return [
            $row['status'] !== EntryStatus::Registered->value ? 3 : ($row['finished'] ? 0 : ($latestIndex !== null ? 1 : 2)),
            $row['finished'] ? 0 : -($latestIndex ?? -1),
            $row['finished'] ? $row['total_ms'] : ($latestElapsed ?? PHP_INT_MAX),
        ];
    }

    /** Rank each checkpoint independently within the displayed result group. */
    private function withCheckpointPlaces(array $rows): array
    {
        $times = [];
        foreach ($rows as $row) {
            if ($row['status'] !== EntryStatus::Registered->value) {
                continue;
            }
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
                if ($elapsedMs !== $previous) {
                    $place = $position;
                }
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
