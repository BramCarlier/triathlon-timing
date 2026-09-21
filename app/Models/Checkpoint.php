<?php
namespace App\Models;

use App\Enums\CheckpointKind;
use App\Enums\Discipline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Checkpoint extends Model
{
    protected $fillable = ['race_id', 'name', 'code', 'sequence', 'discipline', 'kind', 'distance_km', 'is_required', 'is_active'];
    protected function casts(): array { return ['discipline' => Discipline::class, 'kind' => CheckpointKind::class, 'distance_km' => 'decimal:3', 'is_required' => 'boolean', 'is_active' => 'boolean']; }
    public function race(): BelongsTo { return $this->belongsTo(Race::class); }
    public function timings(): HasMany { return $this->hasMany(TimingRecord::class); }
}
