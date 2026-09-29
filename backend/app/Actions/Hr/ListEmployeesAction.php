<?php

namespace App\Actions\Hr;

use App\Models\Employee;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListEmployeesAction
{
    public function handle(array $filters): LengthAwarePaginator
    {
        $query = Employee::query()->with([
            'category', 'department', 'position', 'activeWorkAssignments.workLocation.clientCompany',
            // Needed for EmployeeResource::damiin_completed on every row, not just when filtered.
            'guarantor', 'documents.category',
            // Needed for EmployeeResource::is_historical_rejected on every row — see Issue #7.
            'separations',
        ]);

        if (($filters['status'] ?? null) === 'waiting') {
            $query->with('workforceRequestMatches');
        }

        if (($filters['status'] ?? null) === 'inactive') {
            $query->with('latestSeparation.separatedBy');
        }

        if (! empty($filters['search'])) {
            $search = '%'.mb_strtolower($filters['search']).'%';
            $query->where(function ($q) use ($search) {
                // LOWER(...) LIKE, not ILIKE: portable across Postgres (production) and
                // SQLite (the test suite's DB driver) rather than a Postgres-only operator.
                $q->whereRaw('LOWER(full_name) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(phone) LIKE ?', [$search])
                    ->orWhereRaw('LOWER(employee_number) LIKE ?', [$search]);
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['pipeline_stage'])) {
            $query->where('pipeline_stage', $filters['pipeline_stage']);
        }

        if (! empty($filters['employee_category_id'])) {
            $query->where('employee_category_id', $filters['employee_category_id']);
        }

        if (! empty($filters['department_id'])) {
            $query->where('department_id', $filters['department_id']);
        }

        if (! empty($filters['position_id'])) {
            $query->where('position_id', $filters['position_id']);
        }

        if (! empty($filters['location'])) {
            $query->whereRaw('LOWER(location) LIKE ?', ['%'.mb_strtolower($filters['location']).'%']);
        }

        if (! empty($filters['is_supervisor'])) {
            $query->where('is_supervisor', true);
        }

        if (! empty($filters['office_only'])) {
            $query->whereHas('activeWorkAssignments.workLocation', fn ($q) => $q->where('location_type', 'office'));
        }

        // array_key_exists, not empty(): both true and false are meaningful filter
        // values here (e.g. "Profile Incomplete" must be queryable, not just "Complete").
        if (array_key_exists('profile_complete', $filters)) {
            $query->where('profile_complete', $filters['profile_complete']);
        }

        // The generic "Damiin: Yes/No" filter — deliberately unchanged. It shows the
        // raw historical guarantor_needed population for audit/reporting, including
        // employees who have since completed the Damiin requirement.
        if (array_key_exists('guarantor_needed', $filters)) {
            $query->where('guarantor_needed', $filters['guarantor_needed']);
        }

        // The dedicated "Damiin Needed" active-work-queue filter — a different
        // question from the one above: guarantor_needed=true AND NOT damiin_completed
        // (guarantor verified AND at least one verified "Guarantor Documents" upload).
        // Never touches guarantor_needed itself.
        if (! empty($filters['damiin_active'])) {
            $query->where('guarantor_needed', true)->where(function ($q) {
                $q->whereDoesntHave('guarantor', fn ($g) => $g->whereNotNull('verified_at'))
                    ->orWhereDoesntHave('documents', function ($d) {
                        $d->where('verification_status', 'verified')
                            ->whereHas('category', fn ($c) => $c->where('name', 'Guarantor Documents'));
                    });
            });
        }

        // Historical Rejected (Issue #7) — the 598 people migrated from the CANCELED
        // sheet / RED REGISTRATION rows. Deliberately NEVER pipeline_stage='rejected'
        // (that's the live workflow's own outcome, set only by RecordPracticalDecisionAction)
        // — this filters on the existing EmployeeSeparation migration marker instead, so it
        // can never collide with or be confused for a live-workflow rejection.
        if (! empty($filters['historical_rejected'])) {
            $query->whereHas(
                'separations',
                fn ($q) => $q->where('notes', 'like', '%Migrated cancellation from Excel HR workbook%'),
            );
        }

        if (! empty($filters['application_date_from'])) {
            $query->whereDate('application_date', '>=', $filters['application_date_from']);
        }

        if (! empty($filters['application_date_to'])) {
            $query->whereDate('application_date', '<=', $filters['application_date_to']);
        }

        if (! empty($filters['joining_date_from'])) {
            $query->whereDate('joining_date', '>=', $filters['joining_date_from']);
        }

        if (! empty($filters['joining_date_to'])) {
            $query->whereDate('joining_date', '<=', $filters['joining_date_to']);
        }

        return $query
            ->orderByDesc('application_date')
            ->paginate((int) ($filters['per_page'] ?? 15), page: (int) ($filters['page'] ?? 1));
    }
}
