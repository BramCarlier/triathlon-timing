<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\{Athlete, Checkpoint, Entry, Race, TimingRecord, User};
use App\Services\ResultsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LiveStandingsTest extends TestCase
{
    use RefreshDatabase;

    private function race(): Race
    {
        return Race::create([
            'name' => 'Live race', 'slug' => 'live-race', 'event_date' => '2026-09-27',
            'started_at' => now()->subHour(), 'status' => 'running',
            'created_by' => User::factory()->create(['role' => UserRole::Admin])->id,
        ]);
    }

    private function checkpoint(Race $race, int $sequence, string $kind = 'transition', bool $active = true): Checkpoint
    {
        return $race->checkpoints()->create([
            'name' => "Checkpoint $sequence", 'code' => "CP$sequence", 'sequence' => $sequence,
            'kind' => $kind, 'is_active' => $active,
        ]);
    }

    private function timing(Race $race, Entry $entry, Checkpoint $checkpoint, int $time): TimingRecord
    {
        return $race->timings()->create([
            'client_uuid' => (string) Str::uuid(), 'entry_id' => $entry->id,
            'checkpoint_id' => $checkpoint->id, 'elapsed_ms' => $time, 'recorded_at' => now(),
            'status' => 'recorded', 'source' => 'online',
        ]);
    }

    public function test_positions_use_progress_then_full_precision_and_preserve_ties_and_unranked_statuses(): void
    {
        $race = $this->race();
        // Create out of order to ensure course sequence, not insertion order, drives progress.
        $finish = $this->checkpoint($race, 50, 'finish');
        $bike = $this->checkpoint($race, 30);
        $swim = $this->checkpoint($race, 10);
        $inactive = $this->checkpoint($race, 40, active: false);
        $entries = [];
        foreach (['early', 'later', 'tie', 'millisecond', 'winner', 'waiting', 'inactive', 'dns', 'dnf', 'dsq'] as $name) {
            $entries[$name] = $race->entries()->create([
                'type' => $name === 'later' ? 'relay' : 'solo',
                'team_name' => $name === 'later' ? 'Trio team' : null,
                'status' => in_array($name, ['dns', 'dnf', 'dsq']) ? $name : 'registered',
            ]);
        }
        $this->timing($race, $entries['early'], $swim, 0);
        $this->timing($race, $entries['later'], $bike, 60000);
        $this->timing($race, $entries['tie'], $bike, 60000);
        $this->timing($race, $entries['millisecond'], $bike, 60001);
        $this->timing($race, $entries['winner'], $finish, 120000);
        $this->timing($race, $entries['inactive'], $inactive, 90000);
        foreach (['dns', 'dnf', 'dsq'] as $name) $this->timing($race, $entries[$name], $finish, 1000);

        $rows = app(ResultsService::class)->live($race);
        $this->assertSame([1, 2, 2, 4, 5, null, null, null, null, null], array_column($rows, 'place'));
        $this->assertSame(array_map(fn ($name) => $entries[$name]->id, ['winner', 'later', 'tie', 'millisecond', 'early', 'waiting', 'inactive', 'dns', 'dnf', 'dsq']), array_column($rows, 'id'));
        $this->assertSame('Trio team', $rows[1]['name']);
        $this->assertNull($rows[1]['splits'][0]['elapsed_ms']); // Missing swim is never invented.
        $this->assertSame(60000, $rows[1]['latest_elapsed_ms']);
        $this->assertSame(0, $rows[4]['latest_elapsed_ms']);
        $this->assertCount(3, $rows[0]['splits']); // Inactive checkpoint is excluded.
        $this->assertSame('DSQ', $rows[9]['result_status']);
        // The existing final-results ranking remains finish-only.
        $this->assertSame([1, null, null, null, null, null, null, null, null, null], array_column(app(ResultsService::class)->filtered($race), 'place'));
    }

    public function test_corrections_voids_and_finish_changes_recalculate_positions(): void
    {
        $race = $this->race();
        $swim = $this->checkpoint($race, 10);
        $finish = $this->checkpoint($race, 50, 'finish');
        $a = $race->entries()->create(['type' => 'solo']);
        $b = $race->entries()->create(['type' => 'relay', 'team_name' => 'Trio']);
        $time = $this->timing($race, $a, $swim, 10000);
        $this->timing($race, $b, $swim, 12000);
        $service = app(ResultsService::class);
        $this->assertSame($a->id, $service->live($race)[0]['id']);
        $time->update(['elapsed_ms' => 13000]);
        $this->assertSame($b->id, $service->live($race)[0]['id']);
        $finished = $this->timing($race, $a, $finish, 50000);
        $this->assertSame($a->id, $service->live($race)[0]['id']);
        $finished->update(['status' => 'voided']);
        $this->assertSame($b->id, $service->live($race)[0]['id']);
        $time->update(['status' => 'voided']);
        $this->assertNull($service->live($race)[1]['place']);
    }

    public function test_each_checkpoint_has_independent_places_with_ties_filters_and_missing_times(): void
    {
        $race = $this->race();
        $swim = $this->checkpoint($race, 10);
        $bike = $this->checkpoint($race, 30);
        $a = $race->entries()->create(['type' => 'solo', 'category' => 'Open']);
        $b = $race->entries()->create(['type' => 'relay', 'category' => 'Open', 'team_name' => 'Trio']);
        $c = $race->entries()->create(['type' => 'solo', 'category' => 'Masters']);
        $waiting = $race->entries()->create(['type' => 'solo']);
        $aTime = $this->timing($race, $a, $swim, 10000);
        $this->timing($race, $b, $swim, 10000);
        $this->timing($race, $c, $swim, 10001);
        $this->timing($race, $a, $bike, 30000);
        $this->timing($race, $b, $bike, 20000);
        $unranked = [];
        foreach (['dns', 'dnf', 'dsq'] as $status) {
            $entry = $race->entries()->create(['type' => 'solo', 'status' => $status]);
            $unranked[] = $entry;
            $this->timing($race, $entry, $swim, 1);
        }
        $service = app(ResultsService::class);
        $place = fn ($rows, $entry, $checkpoint) => collect(collect($rows)->firstWhere('id', $entry->id)['splits'])->firstWhere('checkpoint_id', $checkpoint->id)['place'];
        foreach ([$service->live($race), $service->filtered($race)] as $rows) {
            $this->assertSame(1, $place($rows, $a, $swim));
            $this->assertSame(1, $place($rows, $b, $swim));
            $this->assertSame(3, $place($rows, $c, $swim));
            $this->assertSame(2, $place($rows, $a, $bike));
            $this->assertSame(1, $place($rows, $b, $bike));
            $this->assertNull($place($rows, $c, $bike));
            $this->assertNull($place($rows, $waiting, $swim));
            foreach ($unranked as $entry) $this->assertNull($place($rows, $entry, $swim));
        }
        $this->assertSame(2, $place($service->filtered($race, ['type' => 'solo']), $c, $swim));
        $this->assertSame(1, $place($service->filtered($race, ['category' => 'Masters']), $c, $swim));
        $aTime->update(['elapsed_ms' => 10002]);
        $this->assertSame(3, $place($service->live($race), $a, $swim));
        $aTime->update(['status' => 'voided']);
        $this->assertNull($place($service->filtered($race), $a, $swim));
        $this->assertSame(2, $place($service->filtered($race), $c, $swim));
    }

    public function test_both_race_pages_expose_refreshable_standings_without_private_athlete_data(): void
    {
        $race = $this->race();
        $checkpoint = $this->checkpoint($race, 10);
        $entry = $race->entries()->create(['type' => 'solo']);
        $athlete = Athlete::create(['first_name' => 'Alex', 'last_name' => 'Runner', 'email' => 'private@example.test']);
        $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => 'swim', 'position' => 1]);
        $this->timing($race, $entry, $checkpoint, 12345);
        $this->get("/race/{$race->public_timing_token}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Races/PublicTiming')->has('standings', 1)
            ->where('standings.0.place', 1)->where('standings.0.latest_elapsed_ms', 12345)->where('standings.0.splits.0.place', 1)
            ->where('standings.0.name', 'Alex Runner')->missing('standings.0.members.0.email'));
        $this->actingAs($race->creator)->get("/races/{$race->id}")->assertOk()->assertInertia(fn (Assert $page) => $page
            ->component('Races/Show')->where('standings.0.place', 1));
        $this->get("/races/{$race->id}", [
            'X-Inertia' => 'true', 'X-Inertia-Partial-Component' => 'Races/Show',
            'X-Inertia-Partial-Data' => 'standings',
        ])->assertOk()->assertJsonPath('props.standings.0.latest_elapsed_ms', 12345)->assertJsonMissingPath('props.participants');
        $race->update(['started_at' => null, 'status' => 'ready']);
        $race->timings()->delete();
        $this->assertSame('Awaiting start', app(ResultsService::class)->live($race)[0]['result_status']);
        $this->assertNull(app(ResultsService::class)->live($race)[0]['place']);
    }
}
