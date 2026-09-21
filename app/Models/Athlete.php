<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Athlete extends Model
{
    protected $fillable = ['first_name', 'last_name', 'email', 'date_of_birth', 'gender', 'club', 'phone', 'metadata'];
    protected function casts(): array { return ['date_of_birth' => 'date', 'metadata' => 'array']; }
    protected $appends = ['full_name'];
    public function getFullNameAttribute(): string { return trim($this->first_name.' '.$this->last_name); }
    public function memberships(): HasMany { return $this->hasMany(EntryMember::class); }
    public function user(): HasOne { return $this->hasOne(User::class); }
}
