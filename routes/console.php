<?php
use Illuminate\Support\Facades\Schedule;
Schedule::command('model:prune')->daily();

Schedule::call(function () {
    \Illuminate\Support\Facades\Cache::put('health.scheduler', now()->toISOString(), 600);
    \App\Jobs\RecordQueueHeartbeat::dispatch();
})->everyMinute()->name('timing-heartbeats')->withoutOverlapping();
Schedule::command('timing:check-reverb-connection')->everyFiveMinutes()->withoutOverlapping();
