<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CheckpointAssignment extends Model
{
    protected $fillable = ['race_id', 'checkpoint_id', 'user_id'];

    public function race(): BelongsTo { return $this->belongsTo(Race::class); }
    public function checkpoint(): BelongsTo { return $this->belongsTo(Checkpoint::class); }
    public function user(): BelongsTo { return $this->belongsTo(User::class); }
}
