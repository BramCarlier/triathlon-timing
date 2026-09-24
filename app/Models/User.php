<?php
namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $attributes = ['is_active' => true, 'force_password_change' => false];
    protected $fillable = ['name', 'email', 'password', 'role', 'athlete_id', 'is_active', 'force_password_change', 'access_role_id'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['invitation_sent_at' => 'datetime', 'email_verified_at' => 'datetime', 'password' => 'hashed', 'role' => UserRole::class, 'is_active' => 'boolean', 'force_password_change' => 'boolean']; }
    public function accessRole(): BelongsTo { return $this->belongsTo(AccessRole::class); }
    public function effectivePermissions(): array
    {
        if ($this->isAdmin()) return \App\Support\Permissions::all();
        if (!$this->isRaceStaff()) return [];
        $role = $this->access_role_id ? $this->accessRole : AccessRole::where('is_default',true)->first();
        return array_values(array_intersect($role?->permissions ?? [], \App\Support\Permissions::official()));
    }
    public function hasPermission(string $permission): bool { return $this->isAdmin() || in_array($permission,$this->effectivePermissions(),true); }
    public function athlete(): BelongsTo { return $this->belongsTo(Athlete::class); }
    public function races(): BelongsToMany { return $this->belongsToMany(Race::class)->withTimestamps(); }
    public function checkpointAssignments(): HasMany { return $this->hasMany(CheckpointAssignment::class); }
    public function isAdmin(): bool { return $this->role === UserRole::Admin; }
    public function isRaceStaff(): bool { return in_array($this->role, [UserRole::Admin, UserRole::Official], true); }
}
