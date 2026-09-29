<?php

namespace App\Models;

use App\Enums\DataIssueModule;
use App\Enums\DataIssueSeverity;
use App\Enums\DataIssueStatus;
use App\Enums\DataIssueType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Data Issues & Reconciliation Center. Recording an issue here never
 * modifies the data it describes — see the migration's own docblock.
 */
#[Fillable([
    'issue_number', 'historical_issue_key', 'title', 'description', 'module', 'issue_type', 'severity', 'status',
    'expected_value', 'actual_value', 'difference_value',
    'root_cause', 'resolution', 'source_reference', 'notes',
    'created_by', 'resolved_by', 'resolved_at',
])]
class DataIssue extends Model
{
    protected function casts(): array
    {
        return [
            'module' => DataIssueModule::class,
            'issue_type' => DataIssueType::class,
            'severity' => DataIssueSeverity::class,
            'status' => DataIssueStatus::class,
            'resolved_at' => 'datetime',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by');
    }

    public function affectedRecords(): HasMany
    {
        return $this->hasMany(DataIssueAffectedRecord::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(DataIssueAuditLog::class)->latest('created_at');
    }
}
