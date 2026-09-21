<?php
namespace App\Events;
use App\Models\TimingRecord;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class TimingVoided implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;
    public function __construct(public TimingRecord $timing) {}
    public function broadcastOn(): array { return [new PrivateChannel('race.'.$this->timing->race_id)]; }
    public function broadcastAs(): string { return 'timing.voided'; }
    public function broadcastWith(): array { return ['id' => $this->timing->id, 'entry_id' => $this->timing->entry_id, 'checkpoint_id' => $this->timing->checkpoint_id]; }
}
