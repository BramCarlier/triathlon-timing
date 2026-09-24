<?php
namespace App\Models;

use App\Enums\RaceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

class Race extends Model
{
    use SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.v';
    protected $hidden = ['public_timing_token'];
    protected $fillable = ['name', 'slug', 'event_date', 'timezone', 'status', 'started_at', 'finished_at', 'settings', 'created_by', 'public_results_token', 'public_timing_token', 'results_published_at'];
    protected function casts(): array { return ['results_published_at'=>'datetime', 'event_date' => 'date:Y-m-d', 'status' => RaceStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'settings' => 'array']; }
    protected static function booted(): void
    {
        static::creating(function (Race $race) {
            $race->public_timing_token ??= (string) Str::uuid();
        });
    }

    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function staff(): BelongsToMany { return $this->belongsToMany(User::class)->withTimestamps(); }
    /** @deprecated Use staff() in new code. */
    public function organizers(): BelongsToMany { return $this->staff(); }
    public function entries(): HasMany { return $this->hasMany(Entry::class); }
    public function checkpoints(): HasMany { return $this->hasMany(Checkpoint::class)->orderBy('sequence'); }
    public function timings(): HasMany { return $this->hasMany(TimingRecord::class); }
    public function checkpointAssignments(): HasMany { return $this->hasMany(CheckpointAssignment::class); }
    public function isRunning(): bool { return $this->status === RaceStatus::Running && $this->started_at !== null; }
}
