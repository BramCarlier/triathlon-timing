<?php
namespace App\Events;
use App\Models\TimingRecord;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
class TimingRecorded implements ShouldBroadcastNow
{
    use Dispatchable, SerializesModels;
    public function __construct(public TimingRecord $timing) {}
    public function broadcastOn(): array { return [new PrivateChannel('race.'.$this->timing->race_id)]; }
    public function broadcastAs(): string { return 'timing.recorded'; }
    public function broadcastWith(): array {
        $this->timing->loadMissing(['entry.members.athlete', 'checkpoint', 'operator']);
        return [
            'id' => $this->timing->id,
            'client_uuid' => $this->timing->client_uuid,
            'entry_id' => $this->timing->entry_id,
            'bib_number' => $this->timing->entry->bib_number,
            'checkpoint_id' => $this->timing->checkpoint_id,
            'checkpoint' => $this->timing->checkpoint->name,
            'elapsed_ms' => $this->timing->elapsed_ms,
            'recorded_at' => $this->timing->recorded_at->toISOString(),
            'operator' => $this->timing->operator?->name,
        ];
    }
}
