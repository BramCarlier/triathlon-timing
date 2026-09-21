<?php
namespace App\Models;

use App\Enums\UserRole;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = ['name', 'email', 'password', 'role', 'athlete_id', 'is_active', 'force_password_change'];
    protected $hidden = ['password', 'remember_token'];
    protected function casts(): array { return ['email_verified_at' => 'datetime', 'password' => 'hashed', 'role' => UserRole::class, 'is_active' => 'boolean', 'force_password_change' => 'boolean']; }
    public function athlete(): BelongsTo { return $this->belongsTo(Athlete::class); }
    public function races(): BelongsToMany { return $this->belongsToMany(Race::class)->withTimestamps(); }
    public function isAdmin(): bool { return $this->role === UserRole::Admin; }
    public function isOrganizer(): bool { return in_array($this->role, [UserRole::Admin, UserRole::Organizer], true); }
}
