<?php

namespace Tests\Feature;

use App\Enums\UserRole;
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
        $this->assertSame(4, $race->fresh()->checkpoints()->count());
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

    public function test_athletes_cannot_be_assigned_organizer_permissions_from_race_settings(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $athlete = User::factory()->create(['role' => UserRole::Athlete]);
        $race = Race::create(['name' => 'Test', 'slug' => 'test', 'event_date' => '2026-09-21', 'created_by' => $admin->id]);
        $this->actingAs($admin)->put("/races/{$race->id}", ['name' => 'Test', 'event_date' => '2026-09-21', 'timezone' => 'Europe/Brussels', 'status' => 'ready', 'organizer_ids' => [$athlete->id]])->assertSessionHasErrors('organizer_ids.0');
        $this->assertSame(0, $race->organizers()->count());
    }
}
