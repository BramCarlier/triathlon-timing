<?php
use Illuminate\Support\Facades\Broadcast;

Broadcast::channel('race.{raceId}', function ($user, int $raceId) {
    if ($user->isAdmin()) {
        return true;
    }

    if ($user->role->value === 'athlete') {
        return $user->athlete?->memberships()
            ->whereHas('entry', fn ($query) => $query->where('race_id', $raceId))
            ->exists() ?? false;
    }

    return $user->races()->whereKey($raceId)->exists();
});
