<?php

namespace App\Http\Resources\Api\V1\Admin;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class NotificationTemplateResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'template_key' => $this->template_key,
            'name' => $this->name,
            'type' => $this->type->value,
            'channel' => $this->channel->value,
            'language' => $this->language,
            'status' => $this->status->value,
            'variables' => $this->variables,
        ];
    }
}
