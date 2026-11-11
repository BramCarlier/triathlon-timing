<?php

namespace App\Support;

use App\Models\Race;
use Illuminate\Support\Facades\Cache;

/**
 * A per-race version number, bumped whenever data that feeds results changes.
 * Polling devices share one results computation per version.
 */
class ResultsCache
{
    public static function key(Race $race, string $variant): string
    {
        $version = Cache::get(self::versionKey($race->id), 0);

        return implode(':', [
            'results', $variant, $race->id, $version,
            $race->started_at?->getTimestampMs() ?? '-',
            $race->finished_at?->getTimestampMs() ?? '-',
        ]);
    }

    public static function bump(?int $raceId): void
    {
        if (! $raceId) {
            return;
        }
        $key = self::versionKey($raceId);
        if (! Cache::add($key, 1, now()->addDays(30))) {
            Cache::increment($key);
        }
    }

    private static function versionKey(int $raceId): string
    {
        return "results:version:{$raceId}";
    }
}
