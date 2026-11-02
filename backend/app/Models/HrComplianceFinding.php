<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The tracking row of one HR compliance finding (see the hr_compliance_findings migration and App\Support\Hr\Compliance\ComplianceReport).
 * The facts are rewritten by every scan; status, note and the resolution fields belong to the HR Manager's follow-up.
 */
#[Fillable([
    'finding_key', 'category', 'code', 'severity', 'confidence', 'employee_id', 'assignment_id', 'client_company_id', 'employee_name', 'employee_number',
    'company_name', 'position_name', 'shift', 'employment_type', 'employment_status', 'assignment_status', 'requirement', 'explanation',
    'recommended_action', 'details', 'responsible_admin_id', 'responsible_action', 'responsible_at', 'appointed_on', 'first_detected_at',
    'last_detected_at', 'status', 'resolution_source', 'resolved_at', 'resolved_by', 'note', 'updated_by',
])]
class HrComplianceFinding extends Model
{
    public const STATUS_OPEN = 'open';

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_RESOLVED = 'resolved';

    public const CONFIRMED = 'confirmed';

    public const NEEDS_REVIEW = 'needs_review';

    /** @return list<string> */
    public static function statuses(): array
    {
        return [self::STATUS_OPEN, self::STATUS_IN_PROGRESS, self::STATUS_RESOLVED];
    }

    protected function casts(): array
    {
        return [
            'details' => 'array',
            'responsible_at' => 'datetime',
            'appointed_on' => 'date',
            'first_detected_at' => 'datetime',
            'last_detected_at' => 'datetime',
            'resolved_at' => 'datetime',
        ];
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'responsible_admin_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'resolved_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'updated_by');
    }
}
