<?php

namespace Tests\Feature;

use App\Enums\CheckpointKind;
use App\Enums\Discipline;
use App\Enums\EntryType;
use App\Enums\RaceStatus;
use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\CheckpointAssignment;
use App\Models\Entry;
use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RaceWorkspaceTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_signed_in_role_has_its_own_guide(): void
    {
        foreach ([UserRole::Admin, UserRole::Organizer, UserRole::Athlete] as $role) {
            $user = User::factory()->create(['role' => $role]);

            $this->actingAs($user)
                ->get('/guide')
                ->assertOk()
                ->assertInertia(fn (Assert $page) => $page
                    ->component('Guide')
                    ->where('role', $role->value));
        }
    }

    public function test_automatic_finish_is_not_a_race_setting(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);

        $this->actingAs($admin)->post('/races', [
            'name' => 'Simple race',
            'event_date' => '2026-10-10',
            'timezone' => 'Europe/Brussels',
        ])->assertSessionHasNoErrors();

        $this->assertArrayNotHasKey('auto_finish', Race::sole()->settings);
    }

    public function test_race_workspace_contains_participants_assignments_and_live_timing_data(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $official = User::factory()->create(['role' => UserRole::Organizer]);
        $race = Race::create([
            'name' => 'Workspace race',
            'slug' => 'workspace-race',
            'event_date' => '2026-10-10',
            'timezone' => 'Europe/Brussels',
            'created_by' => $admin->id,
        ]);
        $race->organizers()->attach([$admin->id, $official->id]);
        $finish = $race->checkpoints()->create([
            'name' => 'Finish',
            'code' => 'FINISH',
            'sequence' => 50,
            'kind' => CheckpointKind::Finish,
            'discipline' => Discipline::Run,
            'is_active' => true,
            'is_required' => true,
        ]);
        CheckpointAssignment::create(['race_id' => $race->id, 'checkpoint_id' => $finish->id, 'user_id' => $official->id]);
        $this->makeEntry($race, '101', 'Alex Runner');

        $this->actingAs($admin)
            ->get("/races/{$race->id}")
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('Races/Show')
                ->has('participants', 1)
                ->has('checkpointAssignments', 1)
                ->has('recentTimings', 0)
                ->where('completedCount', 0));
    }

    public function test_last_registered_finisher_always_finishes_the_race_automatically(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = $this->runningRace($admin);
        $finish = $race->checkpoints()->create([
            'name' => 'Finish',
            'code' => 'FINISH',
            'sequence' => 50,
            'kind' => CheckpointKind::Finish,
            'discipline' => Discipline::Run,
            'is_active' => true,
            'is_required' => true,
        ]);
        $first = $this->makeEntry($race, '101', 'Alex Runner');
        $second = $this->makeEntry($race, '102', 'Sam Rider');

        $this->actingAs($admin)->postJson("/races/{$race->id}/timings", [
            'entry_id' => $first->id,
            'checkpoint_id' => $finish->id,
            'client_uuid' => (string) Str::uuid(),
            'source' => 'online',
            'workspace' => true,
        ])->assertOk()->assertJsonPath('auto_finished', false);

        $this->assertNull($race->fresh()->finished_at);

        $this->postJson("/races/{$race->id}/timings", [
            'entry_id' => $second->id,
            'checkpoint_id' => $finish->id,
            'client_uuid' => (string) Str::uuid(),
            'source' => 'online',
            'workspace' => true,
        ])->assertOk()->assertJsonPath('auto_finished', true);

        $this->assertNotNull($race->fresh()->finished_at);
        $this->assertSame(RaceStatus::Finished, $race->fresh()->status);
    }

    public function test_official_is_locked_to_the_assigned_checkpoint(): void
    {
        Event::fake();
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $official = User::factory()->create(['role' => UserRole::Organizer]);
        $race = $this->runningRace($admin);
        $race->organizers()->attach($official);
        $swim = $race->checkpoints()->create([
            'name' => 'Swim exit',
            'code' => 'SWIM',
            'sequence' => 10,
            'kind' => CheckpointKind::Transition,
            'discipline' => Discipline::Swim,
            'is_active' => true,
            'is_required' => true,
        ]);
        $run = $race->checkpoints()->create([
            'name' => 'Run split',
            'code' => 'RUN',
            'sequence' => 20,
            'kind' => CheckpointKind::Split,
            'discipline' => Discipline::Run,
            'is_active' => true,
            'is_required' => true,
        ]);
        CheckpointAssignment::create(['race_id' => $race->id, 'checkpoint_id' => $swim->id, 'user_id' => $official->id]);
        $entry = $this->makeEntry($race, '301', 'Assigned Official');

        $this->actingAs($official)
            ->post("/races/{$race->id}/checkpoint-selection", ['checkpoint_id' => $run->id])
            ->assertForbidden();

        $this->postJson("/races/{$race->id}/timings", [
            'entry_id' => $entry->id,
            'checkpoint_id' => $run->id,
            'client_uuid' => (string) Str::uuid(),
            'source' => 'online',
        ])->assertForbidden();

        $this->postJson("/races/{$race->id}/timings", [
            'entry_id' => $entry->id,
            'checkpoint_id' => $swim->id,
            'client_uuid' => (string) Str::uuid(),
            'source' => 'online',
        ])->assertOk();
    }

    public function test_setup_and_registration_are_locked_after_start(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $race = $this->runningRace($admin);

        $this->actingAs($admin)->put("/races/{$race->id}", [
            'name' => 'Changed',
            'event_date' => '2026-10-10',
            'timezone' => 'Europe/Brussels',
            'status' => RaceStatus::Running->value,
        ])->assertSessionHasErrors('race');

        $this->post("/races/{$race->id}/participants", [])->assertSessionHasErrors('race');
        $this->post("/races/{$race->id}/checkpoints", [])->assertSessionHasErrors('race');
    }

    private function runningRace(User $admin): Race
    {
        $race = Race::create([
            'name' => 'Race day',
            'slug' => 'race-day-'.Str::lower(Str::random(5)),
            'event_date' => '2026-10-10',
            'timezone' => 'Europe/Brussels',
            'status' => RaceStatus::Running,
            'started_at' => now()->subMinute(),
            'settings' => ['swim_km' => 1, 'bike_km' => 35, 'run_km' => 8],
            'created_by' => $admin->id,
        ]);
        $race->organizers()->attach($admin);

        return $race;
    }

    private function makeEntry(Race $race, string $bib, string $name): Entry
    {
        [$firstName, $lastName] = array_pad(explode(' ', $name, 2), 2, 'Athlete');
        $athlete = Athlete::create(['first_name' => $firstName, 'last_name' => $lastName]);
        $entry = Entry::create(['race_id' => $race->id, 'bib_number' => $bib, 'type' => EntryType::Solo]);
        foreach ([Discipline::Swim, Discipline::Bike, Discipline::Run] as $index => $discipline) {
            $entry->members()->create(['athlete_id' => $athlete->id, 'discipline' => $discipline, 'position' => $index + 1]);
        }

        return $entry;
    }
}
