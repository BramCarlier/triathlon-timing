<?php
require __DIR__.'/../../vendor/autoload.php';
$app=require __DIR__.'/../../bootstrap/app.php';$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if(!$app->environment('testing')||config('database.default')!=='mysql')throw new RuntimeException('Concurrency workers require testing MySQL.');
[$script,$raceId,$entryId,$checkpointId,$operatorId,$uuid]=$argv;
try {
    $record=app(App\Services\TimingService::class)->record(App\Models\Race::findOrFail($raceId),App\Models\Entry::findOrFail($entryId),App\Models\Checkpoint::findOrFail($checkpointId),App\Models\User::findOrFail($operatorId),['client_uuid'=>$uuid,'source'=>'online']);
    echo 'saved:'.$record->id;
} catch(App\Exceptions\TimingConflictException){echo 'conflict';}
