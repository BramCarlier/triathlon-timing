<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
class AccessRole extends Model
{
    protected $fillable = ['name','description','permissions'];
    protected function casts(): array { return ['permissions'=>'array','is_default'=>'boolean']; }
    public function users(): HasMany { return $this->hasMany(User::class); }
}
