<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use App\Models\ImportRow;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * One staged/processed row for the preview. `details` and `meta` come from the staging payload, which holds
 * personal data; they are present only while the batch is retained and are null after the 30-day purge.
 *
 * @mixin ImportRow
 */
class ImportRowResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'row_number' => $this->row_number,
            'status' => $this->status->value,
            'code' => $this->code,
            'message' => $this->message,
            'record_type' => $this->entity_type === null ? null : class_basename($this->entity_type),
            'record_id' => $this->entity_id,
            // The payment/account number is never shown in the preview, only whether the row has one.
            'details' => $this->payload === null ? null : array_diff_key($this->payload, ['payment_account_number' => true]) + (isset($this->payload['payment_account_number']) ? ['has_payment_account' => true] : []),
            'meta' => $this->meta,
            'processed_at' => $this->processed_at?->toIso8601String(),
        ];
    }
}
