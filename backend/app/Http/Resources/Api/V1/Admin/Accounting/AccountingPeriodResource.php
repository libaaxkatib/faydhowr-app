<?php

namespace App\Http\Resources\Api\V1\Admin\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountingPeriodResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date?->toDateString(),
            'end_date' => $this->end_date?->toDateString(),
            'status' => $this->status->value,
            'closed_at' => $this->closed_at?->toISOString(),
            'closed_by' => $this->closed_by,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
