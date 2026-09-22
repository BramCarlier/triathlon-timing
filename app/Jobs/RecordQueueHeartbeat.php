<?php
namespace App\Jobs;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
class RecordQueueHeartbeat implements ShouldQueue {
    use Queueable;
    public function handle():void {Cache::put('health.queue',now()->toISOString(),600);}
}
