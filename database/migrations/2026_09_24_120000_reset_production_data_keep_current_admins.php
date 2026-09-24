<?php

use App\Support\Permissions;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (! app()->environment('production')) {
            return;
        }

        if (parse_url((string) config('app.url'), PHP_URL_HOST) !== 'triathlon-timing.on-forge.com') {
            return;
        }

        if (DB::connection()->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Production reset aborted: expected the MySQL production database.');
        }

        if (DB::table('users')->count() === 0) {
            return;
        }

        $expectedNames = ['Bram Carlier', 'Bo Carlier'];
        $admins = DB::table('users')
            ->whereIn('name', $expectedNames)
            ->orderBy('name')
            ->get();

        if ($admins->count() !== 2
            || $admins->pluck('name')->sort()->values()->all() !== collect($expectedNames)->sort()->values()->all()
            || $admins->contains(fn ($user) => $user->role !== 'admin')) {
            throw new \RuntimeException('Production reset aborted: the two expected administrator accounts were not found exactly as required.');
        }

        $preserved = $admins->mapWithKeys(fn ($user) => [
            $user->id => [
                'name' => $user->name,
                'email' => $user->email,
                'password' => $user->password,
                'role' => $user->role,
            ],
        ])->all();
        $preservedIds = array_keys($preserved);

        $tableNames = collect(DB::select(
            "select table_name as name from information_schema.tables where table_schema = database() and table_type = 'BASE TABLE'"
        ))->map(fn ($row) => $row->name)->values();

        DB::transaction(function () use ($preservedIds, $tableNames): void {
            DB::table('users')
                ->whereIn('id', $preservedIds)
                ->update([
                    'athlete_id' => null,
                    'access_role_id' => null,
                ]);

            DB::statement('SET FOREIGN_KEY_CHECKS=0');

            try {
                $tableNames
                    ->reject(fn (string $table) => in_array($table, ['migrations', 'users'], true))
                    ->each(fn (string $table) => DB::table($table)->delete());

                DB::table('users')
                    ->whereNotIn('id', $preservedIds)
                    ->delete();

                DB::table('access_roles')->insert([
                    'name' => 'Official',
                    'description' => 'Default access for race officials.',
                    'is_default' => true,
                    'permissions' => json_encode(Permissions::official()),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            } finally {
                DB::statement('SET FOREIGN_KEY_CHECKS=1');
            }
        });

        $remainingUsers = DB::table('users')->orderBy('id')->get();

        if ($remainingUsers->count() !== 2) {
            throw new \RuntimeException('Production reset verification failed: unexpected user count.');
        }

        foreach ($remainingUsers as $user) {
            $before = $preserved[$user->id] ?? null;

            if (! $before
                || $user->name !== $before['name']
                || $user->email !== $before['email']
                || $user->password !== $before['password']
                || $user->role !== $before['role']) {
                throw new \RuntimeException('Production reset verification failed: an administrator account changed unexpectedly.');
            }
        }

        if (DB::table('access_roles')->count() !== 1
            || ! DB::table('access_roles')->where('name', 'Official')->where('is_default', true)->exists()) {
            throw new \RuntimeException('Production reset verification failed: the default Official role was not restored.');
        }

        $domainTables = [
            'races',
            'race_user',
            'athletes',
            'entries',
            'entry_members',
            'checkpoints',
            'timing_records',
            'operator_presence',
            'import_batches',
            'entry_changes',
            'checkpoint_assignments',
        ];

        foreach ($domainTables as $table) {
            if (DB::table($table)->exists()) {
                throw new \RuntimeException("Production reset verification failed: {$table} is not empty.");
            }
        }
    }

    public function down(): void
    {
        // This one-time destructive reset is intentionally irreversible.
    }
};
