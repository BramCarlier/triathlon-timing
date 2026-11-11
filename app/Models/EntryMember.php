<?php
namespace App\Models;

use App\Enums\Discipline;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EntryMember extends Model
{
    protected static function booted(): void
    {
        static::saved(fn (self $model) => \App\Support\ResultsCache::bump($model->entry?->race_id));
        static::deleted(fn (self $model) => \App\Support\ResultsCache::bump($model->entry?->race_id));
    }

    protected $fillable = ['entry_id', 'athlete_id', 'discipline', 'position'];
    protected function casts(): array { return ['discipline' => Discipline::class]; }
    public function entry(): BelongsTo { return $this->belongsTo(Entry::class); }
    public function athlete(): BelongsTo { return $this->belongsTo(Athlete::class); }
}
