<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RaceManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_edit_delete_and_restore_a_race_without_losing_entries(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $this->actingAs($admin)->post('/races', ['name' => 'Club triathlon', 'event_date' => '2026-09-21', 'timezone' => 'Europe/Brussels'])->assertSessionHasNoErrors();
        $race = Race::sole();
        $this->assertSame(6, $race->checkpoints()->count());
        $entry = $race->entries()->create(['type' => 'solo', 'bib_number' => null]);
        $this->put("/races/{$race->id}", ['name' => 'Updated triathlon', 'event_date' => '2026-09-22', 'timezone' => 'Europe/Brussels', 'status' => 'ready', 'organizer_ids' => [$admin->id]])->assertSessionHasNoErrors();
        $this->assertSame('Updated triathlon', $race->fresh()->name);
        $this->delete("/races/{$race->id}")->assertRedirect('/races');
        $this->assertSoftDeleted($race);
        $this->get("/races/{$race->id}/station")->assertNotFound();
        $this->get('/races')->assertInertia(fn (Assert $page) => $page->has('races', 0)->has('deletedRaces', 1));
        $this->post("/races/{$race->id}/restore")->assertRedirect("/races/{$race->id}");
        $this->assertNotNull(Race::find($race->id));
        $this->assertDatabaseHas('entries', ['id' => $entry->id, 'race_id' => $race->id]);
        $this->assertSame(6, $race->fresh()->checkpoints()->count());
        $this->assertTrue($race->fresh()->organizers->contains($admin));
    }

    public function test_organizers_cannot_delete_or_restore_races(): void
    {
        $organizer = User::factory()->create(['role' => UserRole::Organizer]);
        $race = Race::create(['name' => 'Test', 'slug' => 'test', 'event_date' => '2026-09-21', 'created_by' => $organizer->id]);
        $race->organizers()->attach($organizer);
        $this->actingAs($organizer)->delete("/races/{$race->id}")->assertForbidden();
        $race->delete();
        $this->post("/races/{$race->id}/restore")->assertForbidden();
    }

    public function test_only_official_accounts_can_be_assigned_to_checkpoints(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $athlete = User::factory()->create(['role' => UserRole::Athlete]);
        $official = User::factory()->create(['role' => UserRole::Organizer]);
        $race = Race::create(['name' => 'Test', 'slug' => 'test', 'event_date' => '2026-09-21', 'created_by' => $admin->id]);
        $race->organizers()->attach($admin);
        $checkpoint = $race->checkpoints()->create(['name'=>'Finish','code'=>'FINISH','sequence'=>50,'kind'=>'finish','is_active'=>true,'is_required'=>true]);

        $this->actingAs($admin)->post("/races/{$race->id}/official-assignments", [
            'checkpoint_id' => $checkpoint->id,
            'mode' => 'existing',
            'user_id' => $athlete->id,
        ])->assertSessionHasErrors('user_id');

        $this->post("/races/{$race->id}/official-assignments", [
            'checkpoint_id' => $checkpoint->id,
            'mode' => 'existing',
            'user_id' => $official->id,
            'delivery' => 'manual',
            'password' => '',
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('checkpoint_assignments', [
            'race_id' => $race->id,
            'checkpoint_id' => $checkpoint->id,
            'user_id' => $official->id,
        ]);
        $this->assertTrue($official->fresh()->races->contains($race));

        $this->post("/races/{$race->id}/official-assignments", [
            'checkpoint_id' => $checkpoint->id,
            'mode' => 'existing',
            'user_id' => $admin->id,
        ])->assertSessionHasNoErrors();

        $this->assertDatabaseHas('checkpoint_assignments', [
            'race_id' => $race->id,
            'checkpoint_id' => $checkpoint->id,
            'user_id' => $admin->id,
        ]);
    }

    public function test_existing_athlete_can_be_reused_in_different_races_but_not_twice_in_one_race(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $athlete = Athlete::create([
            'first_name' => 'Repeat',
            'last_name' => 'Racer',
            'email' => 'repeat@example.test',
            'club' => 'Tri Club',
        ]);
        $raceOne = Race::create(['name' => 'Race One', 'slug' => 'race-one', 'event_date' => '2026-09-20', 'created_by' => $admin->id]);
        $raceTwo = Race::create(['name' => 'Race Two', 'slug' => 'race-two', 'event_date' => '2026-09-27', 'created_by' => $admin->id]);
        $raceOne->organizers()->attach($admin);
        $raceTwo->organizers()->attach($admin);

        $payload = [
            'bib_number' => null,
            'type' => 'solo',
            'team_name' => null,
            'category' => 'Open',
            'members' => [[
                'discipline' => 'swim',
                'athlete_id' => $athlete->id,
                'first_name' => $athlete->first_name,
                'last_name' => $athlete->last_name,
                'email' => $athlete->email,
                'club' => $athlete->club,
            ]],
        ];

        $this->actingAs($admin)->post("/races/{$raceOne->id}/participants", $payload)->assertSessionHasNoErrors();
        $this->post("/races/{$raceTwo->id}/participants", $payload)->assertSessionHasNoErrors();

        $this->assertSame(2, $athlete->fresh()->memberships()->whereHas('entry', fn ($query) => $query->whereIn('race_id', [$raceOne->id, $raceTwo->id]))->distinct('entry_id')->count('entry_id'));

        $this->post("/races/{$raceTwo->id}/participants", $payload)->assertSessionHasErrors('members');

        $this->getJson("/races/{$raceTwo->id}/athletes/search?q=Repeat")
            ->assertOk()
            ->assertJsonPath('athletes.0.id', $athlete->id)
            ->assertJsonPath('athletes.0.already_in_race', true)
            ->assertJsonPath('athletes.0.race_count', 2);

        $athleteUser = User::factory()->create([
            'role' => UserRole::Athlete,
            'athlete_id' => $athlete->id,
        ]);

        $this->actingAs($athleteUser)->get('/athlete')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->has('entries', 2));

        $this->get("/races/{$raceOne->id}/results")->assertOk();
        $this->get("/races/{$raceTwo->id}/results")->assertOk();
    }
}
