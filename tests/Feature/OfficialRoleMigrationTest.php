<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OfficialRoleMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_legacy_organizer_values_become_official(): void
    {
        $official = User::factory()->create(['role' => UserRole::Official]);
        $admin = User::factory()->create(['role' => UserRole::Admin]);
        DB::table('users')->where('id', $official->id)->update(['role' => 'organizer']);

        $migration = require database_path('migrations/2026_11_12_000100_rename_official_role_value.php');
        $migration->up();

        $this->assertSame(UserRole::Official, $official->fresh()->role);
        $this->assertSame(UserRole::Admin, $admin->fresh()->role);
    }
}
