<?php

namespace App\Http\Resources\Api\V1\Admin\Reconciliation;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DataIssueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'issue_number' => $this->issue_number,
            'title' => $this->title,
            'description' => $this->description,
            'module' => $this->module->value,
            'module_label' => $this->module->label(),
            'issue_type' => $this->issue_type->value,
            'issue_type_label' => $this->issue_type->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'expected_value' => $this->expected_value,
            'actual_value' => $this->actual_value,
            'difference_value' => $this->difference_value,
            'root_cause' => $this->root_cause,
            'resolution' => $this->resolution,
            'source_reference' => $this->source_reference,
            'notes' => $this->notes,
            'affected_records_count' => $this->whenCounted('affectedRecords'),
            'affected_records' => DataIssueAffectedRecordResource::collection($this->whenLoaded('affectedRecords')),
            'audit_logs' => DataIssueAuditLogResource::collection($this->whenLoaded('auditLogs')),
            'created_by' => $this->created_by,
            'created_by_name' => $this->whenLoaded('creator', fn () => $this->creator?->full_name),
            'resolved_by' => $this->resolved_by,
            'resolved_by_name' => $this->whenLoaded('resolver', fn () => $this->resolver?->full_name),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
            'resolved_at' => $this->resolved_at?->toIso8601String(),
        ];
    }
}
