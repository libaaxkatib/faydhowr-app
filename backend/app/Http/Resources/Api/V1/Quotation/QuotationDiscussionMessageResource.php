<?php

namespace App\Http\Resources\Api\V1\Quotation;

use App\Models\QuotationMessageAttachment;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class QuotationDiscussionMessageResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'sender_type' => $this->sender_type,
            'message' => $this->message,
            'attachments' => $this->whenLoaded(
                'attachments',
                fn (): array => $this->attachments
                    ->map(fn (QuotationMessageAttachment $attachment): array => [
                        'uuid' => $attachment->upload?->uuid,
                        'original_name' => $attachment->upload?->original_name,
                        'media_type' => $attachment->upload?->media_type?->value,
                        'mime_type' => $attachment->upload?->mime_type,
                        'file_size_bytes' => $attachment->upload?->file_size_bytes,
                    ])
                    ->all(),
                [],
            ),
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
