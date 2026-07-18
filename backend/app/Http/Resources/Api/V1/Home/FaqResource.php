<?php

namespace App\Http\Resources\Api\V1\Home;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class FaqResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->resource->id,
            'question' => $this->resource->question,
            'answer' => $this->resource->answer,
            'sort_order' => $this->resource->sort_order,
        ];
    }
}
