<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ArchivedNotificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'original_notification_id' => $this->original_notification_id,
            'recipient_type' => $this->recipient_type,
            'recipient_id' => $this->recipient_id,
            'type' => $this->type->value,
            'channel' => $this->channel->value,
            'status' => $this->status->value,
            'title' => $this->title,
            'message' => $this->message,
            'data' => $this->data,
            'processing_started_at' => $this->processing_started_at?->toISOString(),
            'sent_at' => $this->sent_at?->toISOString(),
            'delivered_at' => $this->delivered_at?->toISOString(),
            'read_at' => $this->read_at?->toISOString(),
            'failed_at' => $this->failed_at?->toISOString(),
            'archived_at' => $this->archived_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
