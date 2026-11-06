<?php

namespace App\Support\Hr\Compliance;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\HrComplianceFinding;
use App\Support\ApiResponse;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The HR compliance and accountability report: the scanner's findings, tracked. A scan only writes to hr_compliance_findings - it never touches an
 * employee, assignment, status, salary or payroll record, and it never deactivates or penalises anyone.
 *
 * Owner decision UG-1: reading the report (query, summary, lastScan, history) NEVER writes anything. Only sync(), reached through the explicit
 * "Run scan" action (POST hr/compliance/scan), scans and stores; each such run is audited with who ran it and what it found. There is no schedule.
 *
 * Tracking rules:
 *  - a new issue is stored Open with its first-detected time; a known issue keeps its status, note and first-detected time and has its facts refreshed;
 *  - an issue the scan no longer finds is marked Resolved by the system (resolution_source system_cleared): the condition is gone;
 *  - an issue the system cleared that appears again is reopened; one HR resolved by hand stays resolved (HR has recorded its follow-up);
 *  - a status or note change is always made by a person, with an audit event (who, when, from, to, note).
 */
final class ComplianceReport
{
    public function __construct(private readonly ComplianceScanner $scanner = new ComplianceScanner) {}

    /** @return array{new: int, cleared: int, reopened: int, reviewed: int} */
    public function sync(?Admin $actor = null): array
    {
        $scan = $this->scanner->scan();
        $now = now();
        $seen = [];
        $new = 0;
        $reopened = 0;

        foreach ($scan['findings'] as $finding) {
            $seen[] = $finding['key'];
            $row = HrComplianceFinding::query()->where('finding_key', $finding['key'])->first();
            $facts = collect($finding)->except(['key'])->all();

            if ($row === null) {
                try {
                    HrComplianceFinding::query()->create([...$facts, 'finding_key' => $finding['key'], 'first_detected_at' => $now, 'last_detected_at' => $now, 'status' => HrComplianceFinding::STATUS_OPEN]);
                    $new++;
                } catch (UniqueConstraintViolationException) {
                    // A parallel scan stored the same issue first: it is one issue, one row.
                }

                continue;
            }

            $reopen = $row->status === HrComplianceFinding::STATUS_RESOLVED && $row->resolution_source === 'system_cleared';
            $row->fill([...$facts, 'last_detected_at' => $now]);

            if ($reopen) {
                $row->fill(['status' => HrComplianceFinding::STATUS_OPEN, 'resolution_source' => null, 'resolved_at' => null, 'resolved_by' => null]);
                $reopened++;
            }

            $row->save();
        }

        $cleared = HrComplianceFinding::query()
            ->whereNotIn('finding_key', $seen ?: [''])
            ->where('status', '!=', HrComplianceFinding::STATUS_RESOLVED)
            ->update(['status' => HrComplianceFinding::STATUS_RESOLVED, 'resolution_source' => 'system_cleared', 'resolved_at' => $now, 'updated_at' => $now]);

        // An explicit scan is always recorded (who ran it, when, what it found), so "last scanned" can be shown without a new table.
        if ($actor !== null) {
            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "HR compliance scan: {$new} new, {$reopened} reopened, {$cleared} cleared by the system.",
                entityType: HrComplianceFinding::class,
                entityId: null,
                metadata: ['event' => 'compliance_scan', 'new' => $new, 'reopened' => $reopened, 'cleared' => $cleared, 'employees_reviewed' => $scan['employees_reviewed']],
            ));
        }

        return ['new' => $new, 'cleared' => $cleared, 'reopened' => $reopened, 'reviewed' => $scan['employees_reviewed']];
    }

    /** How many people an explicit scan reviews right now - two cheap read queries, nothing scanned or stored (owner decision UG-1). */
    public function employeesReviewed(): int
    {
        return $this->scanner->employeesInScopeCount();
    }

    /**
     * The most recent explicit scan, read from its audit record (no table of its own): when, who, and what it found. Null when nobody has run one.
     *
     * @return array{at: string|null, by: string|null, new: int, reopened: int, cleared: int, employees_reviewed: int|null}|null
     */
    public function lastScan(): ?array
    {
        $log = AuditLog::query()
            ->with('admin:id,full_name')
            ->where('entity_type', HrComplianceFinding::class)
            ->whereNull('entity_id')
            ->where('description', 'like', 'HR compliance scan:%')
            ->orderByDesc('id')
            ->first();

        if ($log === null) {
            return null;
        }

        return [
            'at' => $log->created_at?->toIso8601String(),
            'by' => $log->admin?->full_name,
            'new' => (int) ($log->metadata['new'] ?? 0),
            'reopened' => (int) ($log->metadata['reopened'] ?? 0),
            'cleared' => (int) ($log->metadata['cleared'] ?? 0),
            'employees_reviewed' => isset($log->metadata['employees_reviewed']) ? (int) $log->metadata['employees_reviewed'] : null,
        ];
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return Builder<HrComplianceFinding>
     */
    public function query(array $filters): Builder
    {
        $q = HrComplianceFinding::query()->with('responsible:id,full_name,role');

        foreach (['category', 'severity', 'status', 'confidence', 'employment_type'] as $field) {
            if (! empty($filters[$field])) {
                $q->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['client_company_id'])) {
            $q->where('client_company_id', (int) $filters['client_company_id']);
        }

        if (! empty($filters['employee_id'])) {
            $q->where('employee_id', (int) $filters['employee_id']);
        }

        if (! empty($filters['search'])) {
            $like = '%'.str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], mb_strtolower(trim((string) $filters['search']))).'%';
            $q->where(fn ($w) => $w->whereRaw("LOWER(employee_name) LIKE ? ESCAPE '\\'", [$like])->orWhereRaw("LOWER(employee_number) LIKE ? ESCAPE '\\'", [$like]));
        }

        if (($filters['responsible_admin_id'] ?? null) === 'none') {
            $q->whereNull('responsible_admin_id');
        } elseif (! empty($filters['responsible_admin_id'])) {
            $q->where('responsible_admin_id', (int) $filters['responsible_admin_id']);
        }

        return $q->orderByRaw("CASE severity WHEN 'high' THEN 0 WHEN 'medium' THEN 1 ELSE 2 END")->orderBy('first_detected_at')->orderBy('id');
    }

    /**
     * Counts over every finding (not only the filtered ones): people are counted once however many findings they have.
     *
     * @param  array{employees_reviewed: int}  $scan
     * @return array<string, int>
     */
    public function summary(int $employeesReviewed): array
    {
        $open = fn (): Builder => HrComplianceFinding::query()->where('status', '!=', HrComplianceFinding::STATUS_RESOLVED);
        $people = fn (string $category): int => $open()->where('category', $category)->distinct()->count('employee_id');

        return [
            'active_employees_reviewed' => $employeesReviewed,
            'incomplete_profiles' => $people(ComplianceScanner::CATEGORY_PROFILE),
            'unmet_job_requirements' => $people(ComplianceScanner::CATEGORY_REQUIREMENTS),
            'incomplete_workflows' => $people(ComplianceScanner::CATEGORY_WORKFLOW),
            'needs_hr_review' => $open()->where('confidence', HrComplianceFinding::NEEDS_REVIEW)->count(),
            'confirmed_open' => $open()->where('confidence', HrComplianceFinding::CONFIRMED)->count(),
            'open' => HrComplianceFinding::query()->where('status', HrComplianceFinding::STATUS_OPEN)->count(),
            'in_progress' => HrComplianceFinding::query()->where('status', HrComplianceFinding::STATUS_IN_PROGRESS)->count(),
            'resolved' => HrComplianceFinding::query()->where('status', HrComplianceFinding::STATUS_RESOLVED)->count(),
            'total_findings' => HrComplianceFinding::query()->count(),
        ];
    }

    /**
     * HR's follow-up: the resolution status and a note. Records nothing on the employee, the assignment or payroll. Audited with who, when, from, to.
     */
    public function updateFollowUp(HrComplianceFinding $finding, Admin $actor, string $status, ?string $note): HrComplianceFinding
    {
        $note = $note === null ? null : trim($note);

        if ($status === HrComplianceFinding::STATUS_RESOLVED && ($note === null || mb_strlen($note) < 3) && ($finding->note === null || trim($finding->note) === '')) {
            throw new HttpResponseException(ApiResponse::error('Write a short note about the corrective action before marking the finding Resolved.', 'COMPLIANCE_NOTE_REQUIRED', 422));
        }

        return DB::transaction(function () use ($finding, $actor, $status, $note): HrComplianceFinding {
            $row = HrComplianceFinding::query()->whereKey($finding->id)->lockForUpdate()->firstOrFail();
            $from = $row->status;
            $noteChanged = $note !== null && $note !== ($row->note ?? '');

            if ($from === $status && ! $noteChanged) {
                return $row;
            }

            $resolved = $status === HrComplianceFinding::STATUS_RESOLVED;
            $row->fill([
                'status' => $status,
                'note' => $note !== null && $note !== '' ? $note : $row->note,
                'updated_by' => $actor->id,
                'resolution_source' => $resolved ? 'manual' : null,
                'resolved_at' => $resolved ? ($from === $status ? $row->resolved_at : now()) : null,
                'resolved_by' => $resolved ? ($from === $status ? $row->resolved_by : $actor->id) : null,
            ])->save();

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "HR compliance finding #{$row->id} ({$row->code}, {$row->employee_number}): status {$from} -> {$status}.",
                entityType: HrComplianceFinding::class,
                entityId: $row->id,
                metadata: ['event' => 'compliance_follow_up', 'finding_key' => $row->finding_key, 'from_status' => $from, 'to_status' => $status, 'note' => $row->note, 'employee_id' => $row->employee_id],
            ));

            return $row->refresh();
        });
    }

    /** The audit trail of one finding: every follow-up change, newest first. @return list<array<string, mixed>> */
    public function history(HrComplianceFinding $finding): array
    {
        return AuditLog::query()
            ->with('admin:id,full_name')
            ->where('entity_type', HrComplianceFinding::class)
            ->where('entity_id', $finding->id)
            ->orderByDesc('id')
            ->get()
            ->map(fn (AuditLog $log): array => [
                'at' => $log->created_at?->toIso8601String(),
                'by' => $log->admin?->full_name,
                'description' => $log->description,
                'from_status' => $log->metadata['from_status'] ?? null,
                'to_status' => $log->metadata['to_status'] ?? null,
                'note' => $log->metadata['note'] ?? null,
            ])
            ->all();
    }
}
