<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobAcceptance extends Model
{
    /** @use HasFactory<\Database\Factories\JobAcceptanceFactory> */
    use HasFactory;

    protected $fillable = [
        'job_id',
        'quote_id',
        'carrier_id',
        'shipper_id',
        'accepted_at',
    ];

    protected function casts(): array
    {
        return [
            'accepted_at' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FreightJob::class, 'job_id');
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(JobQuote::class, 'quote_id');
    }

    public function carrier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'carrier_id');
    }

    public function shipper(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shipper_id');
    }
}
