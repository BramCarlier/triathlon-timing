<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';
$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!$app->environment('testing') || config('database.default')!=='sqlite' || config('database.connections.sqlite.database')!=='/tmp/triathlon-browser.sqlite') {
    throw new RuntimeException('Cache isolation is only allowed in the browser-test database.');
}
// Separate browser engines share a localhost server, but must not share login
// rate-limit counters from prior suites. Production throttling stays unchanged.
Illuminate\Support\Facades\Artisan::call('cache:clear');
