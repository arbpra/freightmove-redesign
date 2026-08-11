<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An enquiry from the public contact form. */
class ContactMessage extends Model
{
    protected $fillable = [
        'name',
        'email',
        'phone',
        'role',
        'subject',
        'message',
        'ip_address',
        'user_agent',
        'user_id',
        'notified_at',
        'handled_at',
    ];

    protected function casts(): array
    {
        return [
            'notified_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    /** Set only when the enquiry came from a signed-in account. */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
