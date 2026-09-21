<?php
namespace App\Models;

use App\Enums\TimingSource;
use App\Enums\TimingStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class TimingRecord extends Model
{
    protected $fillable = ['client_uuid', 'race_id', 'entry_id', 'checkpoint_id', 'athlete_id', 'operator_id', 'recorded_at', 'elapsed_ms', 'source', 'status', 'warning_acknowledged', 'notes', 'voided_at', 'voided_by'];
    protected function casts(): array { return ['recorded_at' => 'datetime:Y-m-d H:i:s.v', 'elapsed_ms' => 'integer', 'source' => TimingSource::class, 'status' => TimingStatus::class, 'warning_acknowledged' => 'boolean', 'voided_at' => 'datetime:Y-m-d H:i:s.v']; }
    public function race(): BelongsTo { return $this->belongsTo(Race::class); }
    public function entry(): BelongsTo { return $this->belongsTo(Entry::class); }
    public function checkpoint(): BelongsTo { return $this->belongsTo(Checkpoint::class); }
    public function athlete(): BelongsTo { return $this->belongsTo(Athlete::class); }
    public function operator(): BelongsTo { return $this->belongsTo(User::class, 'operator_id'); }
    public function voider(): BelongsTo { return $this->belongsTo(User::class, 'voided_by'); }
}
