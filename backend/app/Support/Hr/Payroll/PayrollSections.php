<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use Illuminate\Support\Facades\DB;

/**
 * Company-level payroll sections (see the payroll_run_sections migration): who belongs to which section, what makes a section protected, and how a
 * run's own status follows its sections.
 *
 *  - A run WITHOUT sections (created before this existed) keeps its run-level behaviour everywhere.
 *  - A run WITH sections has the status of its LEAST ADVANCED section. So it is approved / finalized only when every company is, and every reader that
 *    still asks "is this run locked / finalized?" gets the safe answer for a partly finished month (not locked, not finalized).
 *  - A line follows its own section; a line with no section follows its run.
 */
final class PayrollSections
{
    /** The company an assignment belongs to (its own company, else its work location's company); null when it names none. @param list<int> $assignmentIds @return array<int, ?int> */
    public static function companyIdsByAssignment(array $assignmentIds): array
    {
        if ($assignmentIds === []) {
            return [];
        }

        $map = [];

        foreach (array_chunk($assignmentIds, 500) as $chunk) {
            $rows = DB::table('employee_work_assignments as a')
                ->leftJoin('work_locations as l', 'l.id', '=', 'a.work_location_id')
                ->whereIn('a.id', $chunk)
                ->get(['a.id', DB::raw('COALESCE(a.client_company_id, l.client_company_id) as company_id')]);

            foreach ($rows as $row) {
                $map[(int) $row->id] = $row->company_id === null ? null : (int) $row->company_id;
            }
        }

        return $map;
    }

    public static function hasSections(PayrollRun $run): bool
    {
        return $run->sections()->exists();
    }

    public static function rank(string $status): int
    {
        $rank = array_search($status, PayrollRunSection::ORDER, true);

        return $rank === false ? 0 : (int) $rank;
    }

    /** The run's status for these section statuses: the least advanced one. @param iterable<string> $statuses */
    public static function derivedStatus(iterable $statuses): string
    {
        $lowest = null;

        foreach ($statuses as $status) {
            $lowest = $lowest === null || self::rank($status) < self::rank($lowest) ? $status : $lowest;
        }

        return $lowest ?? PayrollRun::STATUS_DRAFT;
    }

    /**
     * Makes the run's status (and its reviewed / approved / finalized stamps) follow its sections. Called after every section change. Nothing happens for
     * a run without sections.
     */
    public static function syncRun(PayrollRun $run): PayrollRun
    {
        $sections = $run->sections()->get();

        if ($sections->isEmpty()) {
            return $run;
        }

        $status = self::derivedStatus($sections->pluck('status'));
        $rank = self::rank($status);
        $latest = fn (string $at, string $by): array => ($s = $sections->whereNotNull($at)->sortByDesc($at)->first()) === null ? [null, null] : [$s->{$at}, $s->{$by}];

        [$reviewedAt, $reviewedBy] = $rank >= 1 ? $latest('reviewed_at', 'reviewed_by') : [null, null];
        [$approvedAt, $approvedBy] = $rank >= 2 ? $latest('approved_at', 'approved_by') : [null, null];
        [$finalizedAt, $finalizedBy] = $rank >= 3 ? $latest('finalized_at', 'finalized_by') : [null, null];

        $run->update([
            'status' => $status,
            'reviewed_at' => $reviewedAt, 'reviewed_by' => $reviewedBy,
            'approved_at' => $approvedAt, 'approved_by' => $approvedBy,
            'finalized_at' => $finalizedAt, 'finalized_by' => $finalizedBy,
        ]);

        return $run;
    }

    /** The status a reader should treat the run as for LOCKING: the run's own when it is locked, "approved" when it is not but some company is protected. */
    public static function lockStatus(PayrollRun $run): string
    {
        if (in_array($run->status, PayrollPeriodLock::LOCKED_STATUSES, true)) {
            return $run->status;
        }

        return $run->sections()->whereIn('status', PayrollRunSection::LOCKED_STATUSES)->exists() ? PayrollRunSection::STATUS_APPROVED : $run->status;
    }

    /** True when this month is locked for that company: the whole run is, or the company's own section is approved / finalized. */
    public static function periodLockedForCompany(string $period, ?int $companyId): bool
    {
        $run = PayrollRun::query()->where('period', $period)->first(['id', 'status']);

        if ($run === null) {
            return false;
        }

        if (in_array($run->status, PayrollPeriodLock::LOCKED_STATUSES, true)) {
            return true;
        }

        return $run->sections()->whereIn('status', PayrollRunSection::LOCKED_STATUSES)
            ->when($companyId === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $companyId))
            ->exists();
    }

    /**
     * The state of a line: the more advanced of its company section and its run. In normal use the two agree or the section is the further one (the
     * run is the least advanced section); the run being the further one only happens to a run that has no sections (then its section is null).
     */
    public static function effective(?string $sectionStatus, string $runStatus): string
    {
        return $sectionStatus !== null && self::rank($sectionStatus) > self::rank($runStatus) ? $sectionStatus : $runStatus;
    }

    /** True when the line is finalized: its company is, or its whole run is. */
    public static function itemFinalized(PayrollItem $item): bool
    {
        $item->loadMissing('section', 'run');

        return self::effective($item->section?->status, $item->run->status) === PayrollRunSection::STATUS_FINALIZED;
    }

    /** True when the line is approved or finalized (protected): its company is, or its whole run is. */
    public static function itemLocked(PayrollItem $item): bool
    {
        $item->loadMissing('section', 'run');

        return in_array(self::effective($item->section?->status, $item->run->status), PayrollPeriodLock::LOCKED_STATUSES, true);
    }

    /** The company ids whose section of this run is approved or finalized (null = the "no company" section). @return list<?int> */
    public static function lockedCompanyIds(PayrollRun $run): array
    {
        return $run->sections()->whereIn('status', PayrollRunSection::LOCKED_STATUSES)->get(['client_company_id'])
            ->map(fn (PayrollRunSection $s): ?int => $s->client_company_id === null ? null : (int) $s->client_company_id)
            ->all();
    }

    /**
     * The totals of a section from its stored lines.
     *
     * @return array<string, int>
     */
    public static function totalsOf(PayrollRunSection $section): array
    {
        $row = DB::table('payroll_items')->where('section_id', $section->id)->selectRaw(
            'count(*) as item_count, count(distinct employee_id) as employee_count, coalesce(sum(gross_cents), 0) as gross_cents, coalesce(sum(replacement_deduction_cents), 0) as replacement_deduction_cents, coalesce(sum(other_deduction_cents), 0) as other_deduction_cents, coalesce(sum(adjustment_cents), 0) as adjustment_cents, coalesce(sum(net_cents), 0) as net_cents',
        )->first();

        return array_map('intval', (array) $row);
    }

    /** Stores the section's totals (done whenever its lines are written, so a month of ~120 companies is shown without recalculating anything). */
    public static function refreshTotals(PayrollRunSection $section): void
    {
        $section->update(['totals' => self::totalsOf($section)]);
    }
}
