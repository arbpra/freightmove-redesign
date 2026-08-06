<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Carrier extends Model
{
    /** @use HasFactory<\Database\Factories\CarrierFactory> */
    use HasFactory;

    protected $fillable = [
        'user_id',
        'fleet_size',
        'service_radius_km',
        'preferred_regions',
        'insurance_provider',
        'insurance_policy_number',
        'operating_since',
    ];

    protected function casts(): array
    {
        return [
            'preferred_regions' => 'array',
            'fleet_size' => 'integer',
            'service_radius_km' => 'integer',
            'operating_since' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function vehicleTypes(): HasMany
    {
        return $this->hasMany(VehicleType::class);
    }
}
