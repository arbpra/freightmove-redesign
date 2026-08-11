<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SubscriptionPlan extends Model
{
    protected $fillable = [
        'legacy_id',
        'code',
        'name',
        'price',
        'compare_at_price',
        'currency',
        'interval_months',
        'is_active',
        'is_trial',
        'sort_order',
    ];

    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'compare_at_price' => 'decimal:2',
            'is_active' => 'boolean',
            'is_trial' => 'boolean',
            'interval_months' => 'integer',
            'sort_order' => 'integer',
        ];
    }

    public function subscriptions(): HasMany
    {
        return $this->hasMany(Subscription::class);
    }

    /**
     * The monthly equivalent, which is what makes the longer plans comparable.
     *
     * The pricing page advertises "Save $3.33 Per Month" on quarterly, which is
     * this figure against the monthly plan's price.
     */
    public function monthlyEquivalent(): float
    {
        return $this->interval_months > 0
            ? round(((float) $this->price) / $this->interval_months, 2)
            : (float) $this->price;
    }
}
