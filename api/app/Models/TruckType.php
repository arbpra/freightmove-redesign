<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A trailer or truck configuration a load can be carried on. */
class TruckType extends Model
{
    protected $fillable = ['name', 'slug', 'sort_order', 'is_active'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function freightJobs(): BelongsToMany
    {
        return $this->belongsToMany(FreightJob::class);
    }

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}
