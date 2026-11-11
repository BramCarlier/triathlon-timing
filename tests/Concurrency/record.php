<?php

use App\Exceptions\TimingConflictException;
use App\Models\Checkpoint;
use App\Models\Entry;
use App\Models\Race;
use App\Models\User;
use App\Services\TimingService;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
if (! $app->environment('testing') || config('database.default') !== 'mysql') {
    throw new RuntimeException('Concurrency workers require testing MySQL.');
}
[$script,$raceId,$entryId,$checkpointId,$operatorId,$uuid] = $argv;
try {
    $record = app(TimingService::class)->record(Race::findOrFail($raceId), Entry::findOrFail($entryId), Checkpoint::findOrFail($checkpointId), User::findOrFail($operatorId), ['client_uuid' => $uuid, 'source' => 'online']);
    echo 'saved:'.$record->id;
} catch (TimingConflictException) {
    echo 'conflict';
}
