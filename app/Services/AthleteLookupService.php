<?php

namespace App\Services;

use App\Models\Athlete;
use App\Models\Race;
use Illuminate\Support\Collection;

class AthleteLookupService
{
    public function options(Race $race, string $search = '', string $bib = '', int $limit = 20): Collection
    {
        $search = trim($search);
        $bib = trim($bib);
        $tokens = mb_strlen($search) >= 2
            ? (preg_split('/\\s+/', mb_strtolower($search), -1, PREG_SPLIT_NO_EMPTY) ?: [])
            : [];

        return Athlete::query()
            ->with(['memberships.entry.race:id,name,event_date'])
            ->when($tokens || $bib !== '', function ($query) use ($tokens, $bib) {
                $query->where(function ($match) use ($tokens, $bib) {
                    if ($tokens) {
                        $match->where(function ($textQuery) use ($tokens) {
                            foreach ($tokens as $token) {
                                $textQuery->where(function ($part) use ($token) {
                                    $like = '%'.$token.'%';
                                    $part->whereRaw('LOWER(first_name) LIKE ?', [$like])
                                        ->orWhereRaw('LOWER(last_name) LIKE ?', [$like])
                                        ->orWhereRaw('LOWER(COALESCE(email, \'\')) LIKE ?', [$like]);
                                });
                            }
                        });
                    }

                    if ($bib !== '') {
                        $method = $tokens ? 'orWhereHas' : 'whereHas';
                        $match->{$method}('memberships.entry', fn ($entry) => $entry->where('bib_number', 'like', '%'.$bib.'%'));
                    }
                });
            })
            ->orderByDesc('updated_at')
            ->orderBy('last_name')
            ->orderBy('first_name')
            ->limit($limit)
            ->get()
            ->map(function (Athlete $athlete) use ($race) {
                $entries = $athlete->memberships
                    ->pluck('entry')
                    ->filter();
                $races = $entries
                    ->pluck('race')
                    ->filter()
                    ->unique('id')
                    ->sortByDesc('event_date')
                    ->values();

                return [
                    'id' => $athlete->id,
                    'full_name' => $athlete->full_name,
                    'first_name' => $athlete->first_name,
                    'last_name' => $athlete->last_name,
                    'email' => $athlete->email,
                    'club' => $athlete->club,
                    'race_count' => $races->count(),
                    'already_in_race' => $races->contains('id', $race->id),
                    'races' => $races->take(3)->map(fn ($item) => [
                        'id' => $item->id,
                        'name' => $item->name,
                        'event_date' => $item->event_date?->toDateString() ?? (string) $item->event_date,
                    ])->all(),
                    'recent_bibs' => $entries
                        ->sortByDesc(fn ($entry) => $entry->race?->event_date)
                        ->pluck('bib_number')
                        ->filter()
                        ->unique()
                        ->take(3)
                        ->values()
                        ->all(),
                ];
            })
            ->values();
    }
}
