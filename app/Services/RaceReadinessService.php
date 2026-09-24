<?php

namespace App\Services;

use App\Enums\CheckpointKind;
use App\Models\Race;

class RaceReadinessService
{
    public function for(Race $race): array
    {
        $settings = $race->settings ?? [];
        $distanceKeys = ['swim_km', 'bike_km', 'run_km'];
        $hasExplicitCourse = collect($distanceKeys)->contains(fn (string $key) => array_key_exists($key, $settings));
        $courseReady = !$hasExplicitCourse || collect($distanceKeys)
            ->every(fn (string $key) => (float) ($settings[$key] ?? 0) > 0);

        $finishReady = $race->checkpoints()
            ->where('kind', CheckpointKind::Finish->value)
            ->where('is_active', true)
            ->exists();

        $participantCount = $race->entries()->where('status', 'registered')->count();

        $timingCheckpointIds = $race->checkpoints()
            ->where('is_active', true)
            ->where('kind', '!=', CheckpointKind::Start->value)
            ->pluck('id');

        $assignedCheckpointIds = $race->checkpointAssignments()
            ->whereIn('checkpoint_id', $timingCheckpointIds)
            ->pluck('checkpoint_id')
            ->unique();

        $unassignedCount = $timingCheckpointIds->diff($assignedCheckpointIds)->count();

        $checks = [
            [
                'key' => 'course',
                'label' => 'Course',
                'ready' => $courseReady,
                'required' => true,
                'detail' => $courseReady
                    ? ($hasExplicitCourse ? 'Distances are configured.' : 'Course uses the checkpoint configuration.')
                    : 'Set the swim, bike and run distances.',
            ],
            [
                'key' => 'finish',
                'label' => 'Finish checkpoint',
                'ready' => $finishReady,
                'required' => true,
                'detail' => $finishReady ? 'An active finish is configured.' : 'Add or activate a finish checkpoint.',
            ],
            [
                'key' => 'participants',
                'label' => 'Participants',
                'ready' => $participantCount > 0,
                'required' => true,
                'detail' => $participantCount > 0 ? "{$participantCount} active participant".($participantCount === 1 ? '' : 's').' registered.' : 'Add at least one participant.',
            ],
            [
                'key' => 'officials',
                'label' => 'Checkpoint coverage',
                'ready' => $unassignedCount === 0,
                'required' => false,
                'detail' => $unassignedCount === 0 ? 'Every timing checkpoint has an assigned Official.' : "{$unassignedCount} checkpoint".($unassignedCount === 1 ? '' : 's').' still need an Official, or an Organizer can cover them.',
            ],
        ];

        return [
            'ready_to_start' => collect($checks)->where('required', true)->every(fn (array $check) => $check['ready']),
            'checks' => $checks,
            'participant_count' => $participantCount,
            'unassigned_checkpoint_count' => $unassignedCount,
        ];
    }
}
