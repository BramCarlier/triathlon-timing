<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;
use Illuminate\Support\Facades\Storage;

class ImportBatch extends Model
{
    use Prunable;

    protected $fillable = [
        'token',
        'race_id',
        'user_id',
        'original_name',
        'stored_path',
        'status',
        'row_count',
        'preview',
        'warnings',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'preview' => 'array',
            'warnings' => 'array',
            'expires_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::query()->where(function (Builder $query) {
            $query->where('expires_at', '<=', now())
                ->orWhere(function (Builder $imported) {
                    $imported->where('status', 'imported')->where('updated_at', '<=', now()->subDay());
                });
        });
    }

    protected function pruning(): void
    {
        if ($this->stored_path) {
            Storage::disk('local')->delete($this->stored_path);
        }
    }
}
