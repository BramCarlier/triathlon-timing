<?php

use App\Jobs\RecordQueueHeartbeat;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schedule;

Schedule::command('model:prune')->daily();

Schedule::call(function () {
    Cache::put('health.scheduler', now()->toISOString(), 600);
    RecordQueueHeartbeat::dispatch();
})->everyMinute()->name('timing-heartbeats')->withoutOverlapping();
Schedule::command('timing:check-reverb-connection')->everyFiveMinutes()->withoutOverlapping();
