<?php
namespace App\Models;

use App\Enums\EntryType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Entry extends Model
{
    protected $fillable = ['race_id', 'bib_number', 'type', 'team_name', 'category', 'status', 'metadata'];
    protected $appends = ['display_name'];
    protected function casts(): array { return ['type' => EntryType::class, 'metadata' => 'array']; }
    protected static function booted(): void
    {
        static::updating(function (Entry $entry) {
            // A later explicit status change supersedes the automatic decision.
            if ($entry->isDirty('status') && $entry->getOriginal('status') === 'dnf') {
                $metadata = $entry->metadata ?? [];
                unset($metadata['automatic_dnf']);
                $entry->metadata = $metadata;
            }
        });
    }

    public function isAutomaticDnf(): bool
    {
        return $this->status === 'dnf' && ($this->metadata['automatic_dnf'] ?? false) === true;
    }

    public function restoreAfterFinishTiming(?User $operator): void
    {
        if (!$this->isAutomaticDnf()) return;
        $this->update(['status' => 'registered']);
        EntryChange::create([
            'entry_id' => $this->id, 'user_id' => $operator?->id,
            'before' => ['entry' => ['status' => 'dnf']],
            'after' => ['entry' => ['status' => 'registered']],
            'reason' => 'Automatic DNF cleared after a finish time was recorded.',
        ]);
    }

    public function race(): BelongsTo { return $this->belongsTo(Race::class); }
    public function members(): HasMany { return $this->hasMany(EntryMember::class); }
    public function timings(): HasMany { return $this->hasMany(TimingRecord::class); }
    public function displayName(): string { return $this->type === EntryType::Relay ? (string) $this->team_name : (string) optional($this->members->first()?->athlete)->full_name; }
    public function getDisplayNameAttribute(): string { return $this->displayName(); }
}
