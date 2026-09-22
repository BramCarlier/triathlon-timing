<?php
namespace App\Services;

use App\Enums\Discipline;
use App\Enums\EntryType;
use App\Models\Athlete;
use App\Models\Entry;
use App\Models\Race;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use JsonException;
use PhpOffice\PhpSpreadsheet\IOFactory;

class ParticipantImportService
{
    public function parse(string $absolutePath, string $originalName): array
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $rows = match ($extension) {
            'json' => $this->parseJson($absolutePath),
            'csv' => $this->parseCsv($absolutePath),
            'xlsx', 'xls', 'ods' => $this->parseSpreadsheet($absolutePath),
            default => throw ValidationException::withMessages(['file' => 'Use JSON, CSV, XLSX, XLS, or ODS.']),
        };

        return array_values(array_filter(array_map(fn ($row) => $this->normalizeRow($row), $rows), fn ($row) => array_filter($row, fn ($value) => $value !== null && $value !== '') !== []));
    }

    public function preview(array $rows): array
    {
        $warnings = [];
        $groups = $this->groups($rows);
        foreach ($groups as $group) {
            $bib = $this->bib($group->first()) ?? ($group->first()['team_name'] ?? 'without bib');
            $type = strtolower((string) ($group->first()['type'] ?? 'solo'));
            if (in_array($type, ['relay', 'trio', 'team'], true)) {
                $disciplines = $group->pluck('discipline')->filter()->map(fn ($v) => strtolower((string) $v))->unique();
                $wide = $group->contains(fn ($row) => isset($row['swim_first_name']) || isset($row['bike_first_name']) || isset($row['run_first_name']));
                if (!$wide && $disciplines->sort()->values()->all() !== ['bike', 'run', 'swim']) $warnings[] = "Relay bib {$bib} should have exactly one swim, bike, and run athlete.";
            }
        }

        return [
            'row_count' => count($rows),
            'entry_count' => $groups->count(),
            'solo_count' => $groups->filter(fn ($group) => !in_array(strtolower((string) ($group->first()['type'] ?? 'solo')), ['relay', 'trio', 'team'], true))->count(),
            'relay_count' => $groups->filter(fn ($group) => in_array(strtolower((string) ($group->first()['type'] ?? 'solo')), ['relay', 'trio', 'team'], true))->count(),
            'sample' => array_slice($rows, 0, 20),
            'warnings' => array_values(array_unique($warnings)),
        ];
    }

    public function import(Race $race, array $rows): array
    {
        $groups = $this->groups($rows);
        $createdEntries = 0;
        $createdAthletes = 0;

        DB::transaction(function () use ($race, $groups, &$createdEntries, &$createdAthletes) {
            foreach ($groups as $group) {
                $bib = $this->bib($group->first());
                if ($bib !== null && strlen($bib) > 32) throw ValidationException::withMessages(['file' => 'Bib numbers must be 32 characters or fewer.']);
                if ($bib !== null && $race->entries()->where('bib_number', $bib)->exists()) throw ValidationException::withMessages(['file' => "Bib {$bib} already exists in this race."]);

                $first = $group->first();
                $isRelay = in_array(strtolower((string) ($first['type'] ?? 'solo')), ['relay', 'trio', 'team'], true);
                $label = $bib !== null ? "bib {$bib}" : ($first['team_name'] ?? $first['first_name'] ?? 'without bib');
                if (!$isRelay && $group->count() !== 1) throw ValidationException::withMessages(['file' => "Duplicate solo entry {$label}. Each solo athlete needs a separate row and a unique bib when supplied."]);
                if ($group->contains(fn ($row) => in_array(strtolower((string) ($row['type'] ?? 'solo')), ['relay', 'trio', 'team'], true) !== $isRelay)) throw ValidationException::withMessages(['file' => "Entry {$label} mixes solo and relay rows."]);
                if ($isRelay && empty($first['team_name']) && empty($first['team'])) throw ValidationException::withMessages(['file' => 'Relay entries need a team_name.']);
                $entry = Entry::create([
                    'race_id' => $race->id,
                    'bib_number' => $bib,
                    'type' => $isRelay ? EntryType::Relay : EntryType::Solo,
                    'team_name' => $isRelay ? ($first['team_name'] ?? $first['team'] ?? null) : null,
                    'category' => $first['category'] ?? null,
                ]);
                $createdEntries++;

                if ($isRelay) {
                    $members = $this->relayMembers($group->all());
                    foreach ([Discipline::Swim, Discipline::Bike, Discipline::Run] as $index => $discipline) {
                        $data = $members[$discipline->value] ?? null;
                        if (!$data || empty($data['first_name']) || empty($data['last_name'])) throw ValidationException::withMessages(['file' => "Relay {$label} is missing the {$discipline->value} athlete."]);
                        [$athlete, $created] = $this->athlete($data); $createdAthletes += $created ? 1 : 0;
                        $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => $discipline, 'position' => $index + 1]);
                    }
                } else {
                    $data = ['first_name' => $first['first_name'] ?? $first['name'] ?? null, 'last_name' => $first['last_name'] ?? '', 'email' => $first['email'] ?? null, 'club' => $first['club'] ?? null];
                    if (!empty($data['first_name']) && empty($data['last_name']) && str_contains((string) $data['first_name'], ' ')) {
                        $parts = preg_split('/\s+/', trim((string) $data['first_name']));
                        $data['last_name'] = array_pop($parts); $data['first_name'] = implode(' ', $parts);
                    }
                    if (empty($data['first_name']) || empty($data['last_name'])) throw ValidationException::withMessages(['file' => "Solo {$label} needs first_name and last_name."]);
                    [$athlete, $created] = $this->athlete($data); $createdAthletes += $created ? 1 : 0;
                    foreach ([Discipline::Swim, Discipline::Bike, Discipline::Run] as $index => $discipline) $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => $discipline, 'position' => $index + 1]);
                }
            }
        });

        return ['entries' => $createdEntries, 'athletes' => $createdAthletes];
    }

    private function bib(array $row): ?string
    {
        $bib = trim((string) ($row['bib'] ?? $row['bib_number'] ?? ''));
        return $bib === '' ? null : $bib;
    }

    private function groups(array $rows): Collection
    {
        // Missing bibs never collapse unrelated solo rows into a single entry.
        return collect($rows)->groupBy(function ($row, $index) {
            $bib = $this->bib($row);
            if ($bib !== null) return 'bib:'.$bib;
            $relay = in_array(strtolower((string) ($row['type'] ?? 'solo')), ['relay', 'trio', 'team'], true);
            $team = trim((string) ($row['entry_key'] ?? $row['team_name'] ?? $row['team'] ?? ''));
            return $relay && $team !== '' ? 'relay:'.$team : 'row:'.$index;
        });
    }

    private function athlete(array $data): array
    {
        $email = isset($data['email']) && $data['email'] !== '' ? strtolower(trim((string) $data['email'])) : null;
        if ($email && ($existing = Athlete::where('email', $email)->first())) return [$existing, false];
        return [Athlete::create(['first_name' => trim((string) $data['first_name']), 'last_name' => trim((string) $data['last_name']), 'email' => $email, 'club' => $data['club'] ?? null]), true];
    }

    private function relayMembers(array $rows): array
    {
        $first = $rows[0] ?? [];
        if (isset($first['swim_first_name']) || isset($first['bike_first_name']) || isset($first['run_first_name'])) {
            if (count($rows) !== 1) throw ValidationException::withMessages(['file' => 'A wide-format relay must occupy exactly one row. Use distinct entry_key values for teams with the same name and no bib.']);
            $result = [];
            foreach (['swim', 'bike', 'run'] as $discipline) $result[$discipline] = ['first_name' => $first[$discipline.'_first_name'] ?? null, 'last_name' => $first[$discipline.'_last_name'] ?? null, 'email' => $first[$discipline.'_email'] ?? null, 'club' => $first[$discipline.'_club'] ?? null];
            return $result;
        }
        $result = [];
        if (count($rows) !== 3) throw ValidationException::withMessages(['file' => 'A relay needs exactly three rows: swim, bike and run. Use distinct entry_key values for teams with the same name and no bib.']);
        foreach ($rows as $row) {
            $discipline = strtolower((string) ($row['discipline'] ?? ''));
            if (isset($result[$discipline])) throw ValidationException::withMessages(['file' => "A relay has more than one {$discipline} athlete."]);
            if (in_array($discipline, ['swim', 'bike', 'run'], true)) $result[$discipline] = ['first_name' => $row['first_name'] ?? null, 'last_name' => $row['last_name'] ?? null, 'email' => $row['email'] ?? null, 'club' => $row['club'] ?? null];
        }
        return $result;
    }

    private function parseJson(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw ValidationException::withMessages(['file' => 'The JSON file is not valid JSON.']);
        }

        if (!is_array($data)) {
            throw ValidationException::withMessages(['file' => 'The JSON file must contain an array of participants or a participants array.']);
        }

        $rows = is_array($data['participants'] ?? null) ? $data['participants'] : $data;
        if (!array_is_list($rows) || array_filter($rows, fn ($row) => !is_array($row))) {
            throw ValidationException::withMessages(['file' => 'Every participant must be an object in a participants array.']);
        }
        return $rows;
    }

    private function parseCsv(string $path): array
    {
        $handle = fopen($path, 'rb');
        if (!$handle) return [];
        $headers = fgetcsv($handle) ?: [];
        $rows = [];
        while (($values = fgetcsv($handle)) !== false) {
            $values = array_pad($values, count($headers), null);
            $rows[] = array_combine($headers, array_slice($values, 0, count($headers)));
        }
        fclose($handle);
        return $rows;
    }

    private function parseSpreadsheet(string $path): array
    {
        $sheet = IOFactory::load($path)->getActiveSheet();
        $data = $sheet->toArray(null, true, true, false);
        $headers = array_shift($data) ?: [];
        return array_map(function ($values) use ($headers) {
            $values = array_pad($values, count($headers), null);
            return array_combine($headers, array_slice($values, 0, count($headers)));
        }, $data);
    }

    private function normalizeRow(array $row): array
    {
        $normalized = [];
        foreach ($row as $key => $value) {
            $key = Str::snake(trim((string) $key));
            if (is_array($value) || is_object($value)) throw ValidationException::withMessages(['file' => 'Participant fields must contain text or numbers, not nested objects.']);
            $normalized[$key] = is_string($value) ? trim($value) : $value;
        }
        if (isset($normalized['bib_number']) && !isset($normalized['bib'])) $normalized['bib'] = $normalized['bib_number'];
        if (isset($normalized['team']) && !isset($normalized['team_name'])) $normalized['team_name'] = $normalized['team'];
        return $normalized;
    }
}
