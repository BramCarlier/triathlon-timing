<?php
namespace App\Models;

use App\Enums\RaceStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Race extends Model
{
    use SoftDeletes;

    protected $dateFormat = 'Y-m-d H:i:s.v';
    protected $fillable = ['name', 'slug', 'event_date', 'timezone', 'status', 'started_at', 'finished_at', 'settings', 'created_by'];
    protected function casts(): array { return ['event_date' => 'date:Y-m-d', 'status' => RaceStatus::class, 'started_at' => 'datetime', 'finished_at' => 'datetime', 'settings' => 'array']; }
    public function creator(): BelongsTo { return $this->belongsTo(User::class, 'created_by'); }
    public function organizers(): BelongsToMany { return $this->belongsToMany(User::class)->withTimestamps(); }
    public function entries(): HasMany { return $this->hasMany(Entry::class); }
    public function checkpoints(): HasMany { return $this->hasMany(Checkpoint::class)->orderBy('sequence'); }
    public function timings(): HasMany { return $this->hasMany(TimingRecord::class); }
    public function isRunning(): bool { return $this->status === RaceStatus::Running && $this->started_at !== null; }
}
