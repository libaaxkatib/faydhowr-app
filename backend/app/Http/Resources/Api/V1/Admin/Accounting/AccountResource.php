<?php

namespace App\Http\Resources\Api\V1\Admin\Accounting;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AccountResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'code' => $this->code,
            'name' => $this->name,
            'account_type' => $this->account_type->value,
            'account_category' => $this->account_category->value,
            'parent_account_id' => $this->parent_account_id,
            'is_group' => $this->is_group,
            'normal_balance' => $this->normal_balance->value,
            'status' => $this->status->value,
            'created_at' => $this->created_at?->toISOString(),
        ];
    }
}
