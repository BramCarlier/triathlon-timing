<?php
namespace Tests\Feature;
use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
class RoleAccessTest extends TestCase
{
    use RefreshDatabase;
    public function test_athlete_cannot_open_admin_user_management(): void
    {
        $athlete = User::factory()->create(['role'=>UserRole::Athlete]);
        $this->actingAs($athlete)->get('/users')->assertForbidden();
    }
    public function test_admin_can_open_user_management(): void
    {
        $admin = User::factory()->create(['role'=>UserRole::Admin]);
        $this->actingAs($admin)->get('/users')->assertOk();
    }
}
