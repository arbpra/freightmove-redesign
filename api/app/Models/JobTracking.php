<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class JobTracking extends Model
{
    /** @use HasFactory<\Database\Factories\JobTrackingFactory> */
    use HasFactory;

    protected $table = 'job_tracking';

    protected $fillable = [
        'job_id',
        'current_status',
        'last_location',
        'eta',
    ];

    protected function casts(): array
    {
        return [
            'eta' => 'datetime',
        ];
    }

    public function job(): BelongsTo
    {
        return $this->belongsTo(FreightJob::class, 'job_id');
    }
}
