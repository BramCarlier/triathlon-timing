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
                'label' => __('Course'),
                'ready' => $courseReady,
                'required' => true,
                'detail' => $courseReady
                    ? ($hasExplicitCourse ? __('Distances are configured.') : __('Course uses the checkpoint configuration.'))
                    : __('Set the swim, bike and run distances.'),
            ],
            [
                'key' => 'finish',
                'label' => __('Finish checkpoint'),
                'ready' => $finishReady,
                'required' => true,
                'detail' => $finishReady ? __('An active finish is configured.') : __('Add or activate a finish checkpoint.'),
            ],
            [
                'key' => 'participants',
                'label' => __('Athletes'),
                'ready' => $participantCount > 0,
                'required' => true,
                'detail' => $participantCount > 0
                    ? __('Active race entries ready: :count', ['count' => $participantCount])
                    : __('Add at least one athlete or relay team.'),
            ],
            [
                'key' => 'officials',
                'label' => __('Official accounts'),
                'ready' => $unassignedCount === 0,
                'required' => false,
                'detail' => $unassignedCount === 0
                    ? __('Optional named Official assignments are configured.')
                    : __('Optional. Anyone with the race-day link can choose a checkpoint and record times without an account.'),
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
