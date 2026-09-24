<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class PublicRaceTimingTest extends TestCase
{
    use RefreshDatabase;

    public function test_race_day_link_is_available_without_an_account_and_can_record_a_time(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = Race::create([
            'name' => 'Simple Race',
            'slug' => 'simple-race',
            'event_date' => '2026-09-24',
            'started_at' => now()->subMinutes(10),
            'status' => 'running',
            'created_by' => $admin->id,
        ]);
        $checkpoint = $race->checkpoints()->create([
            'name' => 'T1',
            'code' => 'T1',
            'sequence' => 10,
            'kind' => 'transition',
            'discipline' => 'bike',
            'is_required' => true,
            'is_active' => true,
        ]);
        $athlete = Athlete::create(['first_name' => 'Alex', 'last_name' => 'Runner']);
        $entry = $race->entries()->create(['type' => 'solo']);
        foreach (['swim', 'bike', 'run'] as $position => $discipline) {
            $entry->members()->create([
                'athlete_id' => $athlete->id,
                'discipline' => $discipline,
                'position' => $position + 1,
            ]);
        }

        $this->assertNotNull($race->public_timing_token);

        $this->get("/race/{$race->public_timing_token}")
            ->assertOk()
            ->assertHeader('X-Robots-Tag', 'noindex, nofollow')
            ->assertInertia(fn (Assert $page) => $page
                ->component('Races/PublicTiming')
                ->where('race.name', 'Simple Race')
                ->where('auth.user', null)
                ->has('participants', 1)
                ->has('race.checkpoints', 1));

        $this->postJson("/race/{$race->public_timing_token}/timings", [
            'entry_id' => $entry->id,
            'checkpoint_id' => $checkpoint->id,
            'client_uuid' => (string) Str::uuid(),
            'observed_at' => now()->toISOString(),
        ])->assertOk()->assertJsonPath('timing.entry.id', $entry->id);

        $this->assertDatabaseHas('timing_records', [
            'race_id' => $race->id,
            'entry_id' => $entry->id,
            'checkpoint_id' => $checkpoint->id,
            'operator_id' => null,
            'status' => 'recorded',
        ]);
    }

    public function test_unknown_race_day_link_does_not_expose_a_race(): void
    {
        $this->get('/race/'.Str::uuid())->assertNotFound();
        $this->postJson('/race/'.Str::uuid().'/timings', [])->assertNotFound();
    }
}
