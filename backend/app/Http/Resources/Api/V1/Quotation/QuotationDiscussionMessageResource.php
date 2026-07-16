<?php

namespace App\Http\Resources\Api\V1\Quotation;

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
            'attachments' => $this->attachments,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
