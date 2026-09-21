<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
class OperatorPresence extends Model
{
    protected $table = 'operator_presence';
    protected $fillable = ['device_uuid', 'user_id', 'race_id', 'checkpoint_id', 'pending_count', 'last_seen_at'];
    protected function casts(): array { return ['pending_count' => 'integer', 'last_seen_at' => 'datetime']; }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
    public function checkpoint(): BelongsTo { return $this->belongsTo(Checkpoint::class); }
}
