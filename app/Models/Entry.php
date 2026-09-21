<?php
namespace App\Models;

use App\Enums\EntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entry extends Model
{
    protected $fillable = ['race_id', 'bib_number', 'type', 'team_name', 'category', 'status', 'metadata'];
    protected function casts(): array { return ['type' => EntryType::class, 'metadata' => 'array']; }
    public function race(): BelongsTo { return $this->belongsTo(Race::class); }
    public function members(): HasMany { return $this->hasMany(EntryMember::class); }
    public function timings(): HasMany { return $this->hasMany(TimingRecord::class); }
    public function displayName(): string { return $this->type === EntryType::Relay ? (string) $this->team_name : (string) optional($this->members->first()?->athlete)->full_name; }
}
