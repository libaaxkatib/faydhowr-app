<?php

namespace App\Http\Resources\Api\V1\Admin\Reconciliation;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataIssueAffectedRecordResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $record = $this->recordable;

        return [
            'id' => $this->id,
            'recordable_type' => $this->recordable_type,
            'recordable_id' => $this->recordable_id,
            'context_note' => $this->context_note,
            'created_at' => $this->created_at?->toIso8601String(),
            // Read live from the actual record, never duplicated in this table.
            'employee' => $record instanceof Employee ? [
                'id' => $record->id,
                'employee_number' => $record->employee_number,
                'full_name' => $record->full_name,
                'category_name' => $record->relationLoaded('category') ? $record->category?->name : null,
                'status' => $record->status?->value,
                'phone' => $record->phone,
                'deleted_at' => $record->deleted_at?->toIso8601String(),
            ] : null,
        ];
    }
}
