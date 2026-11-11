<?php

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'sqlite') {
    throw new RuntimeException('Browser fixtures require an isolated testing SQLite database.');
}
foreach (['admin', 'official', 'athlete'] as $role) {
    User::factory()->create(['name' => 'Test '.ucfirst($role), 'email' => $role.'@example.test', 'password' => 'browser-test-password-123', 'role' => $role]);
}
require __DIR__.'/responsive-seed.php';
