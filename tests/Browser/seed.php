<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!$app->environment('testing')||config('database.default')!=='sqlite')throw new RuntimeException('Browser fixtures require an isolated testing SQLite database.');
foreach(['admin','organizer','athlete'] as $role)App\Models\User::factory()->create(['name'=>'Test '.ucfirst($role),'email'=>$role.'@example.test','password'=>'browser-test-password-123','role'=>$role]);
require __DIR__.'/responsive-seed.php';
