<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::table('access_roles')->orderBy('id')->get(['id', 'permissions'])->each(function ($role) {
            $permissions = json_decode($role->permissions ?: '[]', true) ?: [];
            DB::table('access_roles')->where('id', $role->id)->update([
                'permissions' => json_encode(array_values(array_intersect($permissions, Permissions::official()))),
                'updated_at' => now(),
            ]);
        });
    }

    public function down(): void
    {
        // Removed Official capabilities are intentionally not restored.
    }
};
