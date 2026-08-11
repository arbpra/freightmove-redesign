<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\Notification
 */
class NotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'type' => $this->type,
            'title' => $this->title,
            'body' => $this->body,
            'is_read' => (bool) $this->is_read,
            'related_type' => $this->related_type,
            'related_id' => $this->related_id,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
