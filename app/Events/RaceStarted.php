<?php
namespace App\Events;

use App\Models\Race;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class RaceStarted implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;
    public function __construct(public Race $race) {}
    public function broadcastOn(): array { return [new PrivateChannel('race.'.$this->race->id)]; }
    public function broadcastAs(): string { return 'race.started'; }
    public function broadcastWith(): array { return ['race_id' => $this->race->id, 'started_at' => $this->race->started_at?->toISOString(), 'server_now' => now()->toISOString()]; }
}
