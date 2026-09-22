<?php

namespace App\Support;

use Illuminate\Broadcasting\BroadcastException;

class RaceBroadcast
{
    public static function dispatch(object $event): void
    {
        try {
            event($event);
        } catch (BroadcastException $exception) {
            // The authoritative change is already committed. Polling reconciles
            // clients while the failed live delivery remains visible in logs.
            report($exception);
        }
    }
}
