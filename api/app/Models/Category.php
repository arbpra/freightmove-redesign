<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** A freight category. Seeded from the values live customers actually select. */
class Category extends Model
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
