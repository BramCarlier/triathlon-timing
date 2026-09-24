<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Athlete;
use App\Models\Race;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    private function data(User $user, array $overrides = []): array
    {
        return array_merge(['name' => $user->name, 'email' => $user->email, 'role' => $user->role->value, 'is_active' => true, 'athlete_id' => $user->athlete_id, 'race_ids' => []], $overrides);
    }

    public function test_admin_can_edit_user_and_change_assigned_race_permissions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $operator = User::factory()->create(['role' => UserRole::Official]);
        $first = Race::create(['name' => 'First', 'slug' => 'first', 'event_date' => '2026-09-21', 'created_by' => $admin->id]);
        $second = Race::create(['name' => 'Second', 'slug' => 'second', 'event_date' => '2026-09-21', 'created_by' => $admin->id]);
        $operator->races()->attach($first);
        $this->actingAs($admin)->get("/users/{$operator->id}/edit")->assertOk();
        $this->put("/users/{$operator->id}", $this->data($operator, ['name' => 'Updated Organizer', 'email' => 'UPDATED@example.com', 'race_ids' => [$second->id]]))->assertSessionHasNoErrors()->assertRedirect('/users');
        $this->assertSame('Updated Organizer', $operator->fresh()->name);
        $this->assertSame('updated@example.com', $operator->fresh()->email);
        $this->actingAs($operator->fresh())->get("/races/{$first->id}")->assertForbidden();
        $this->get("/races/{$second->id}")->assertOk();
        $this->get("/users/{$admin->id}/edit")->assertForbidden();
    }

    public function test_last_active_admin_cannot_be_demoted_or_disabled(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        User::factory()->create(['role' => UserRole::Admin, 'is_active' => false]);
        $this->actingAs($admin)->put("/users/{$admin->id}", $this->data($admin, ['role' => 'organizer']))->assertSessionHasErrors('role');
        $this->put("/users/{$admin->id}", $this->data($admin, ['is_active' => false]))->assertSessionHasErrors('role');
        $this->assertTrue($admin->fresh()->isAdmin());
        $this->assertTrue($admin->fresh()->is_active);
    }

    public function test_role_changes_require_an_athlete_link_and_remove_old_race_access(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $operator = User::factory()->create(['role' => UserRole::Official]);
        $athlete = Athlete::create(['first_name' => 'Test', 'last_name' => 'Athlete']);
        $race = Race::create(['name' => 'Race', 'slug' => 'race', 'event_date' => '2026-09-21', 'created_by' => $admin->id]);
        $operator->races()->attach($race);
        $this->actingAs($admin)->put("/users/{$operator->id}", $this->data($operator, ['role' => 'athlete']))->assertSessionHasErrors('athlete_id');
        $this->put("/users/{$operator->id}", $this->data($operator, ['role' => 'athlete', 'athlete_id' => $athlete->id]))->assertSessionHasNoErrors();
        $this->assertSame(UserRole::Athlete, $operator->fresh()->role);
        $this->assertSame(0, $operator->races()->count());
        $this->put("/users/{$operator->id}", $this->data($operator->fresh(), ['role' => 'organizer']))->assertSessionHasNoErrors();
        $this->assertNull($operator->fresh()->athlete_id);
    }

    public function test_disabled_accounts_lose_access_from_existing_sessions(): void
    {
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        $operator = User::factory()->create(['role' => UserRole::Official]);
        $this->actingAs($admin)->put("/users/{$operator->id}", $this->data($operator, ['is_active' => false]))->assertSessionHasNoErrors();
        $this->actingAs($operator->fresh())->get('/races')->assertRedirect('/login');
        $this->assertGuest();
    }
}
