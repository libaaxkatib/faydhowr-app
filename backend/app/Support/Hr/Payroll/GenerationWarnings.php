<?php

namespace App\Support\Hr\Payroll;

use App\Models\TemporaryReplacement;
use App\Models\TemporaryReplacementWorkday;
use App\Support\Hr\UnresolvedTemporaryReplacements;
use Carbon\CarbonImmutable;

/**
 * The Temporary Replacement situations of ONE company and month that HR must know about when it generates, reviews or finalizes that company's payroll
 * (owner decisions N-2b, D-1, D-8). They are WARNINGS ONLY: nothing here blocks Generate, Review or Finalize, none asks for an extra confirmation, and none
 * is hidden - each names the company, the person and what remains unresolved.
 *
 *   A  a Temporary worker registered as a Replacement and still classified Temporary: no payroll line is calculated for those days;
 *   B  Temporary cover with workdays recorded but not verified in the month;
 *   C  Temporary cover not yet cost-approved in the month;
 *   D  the payroll calculation's own Temporary-related warnings for this company (days outside paid dates, days already unpaid leave, covered days with no
 *      payroll line, a position left uncovered).
 *
 * A, B and C are read from the records. D comes from the calculation, so it exists only when a company is generated; at Review / Finalize the ones stored
 * with the section are shown. The Temporary daily rate is not touched here (Permanent monthly salary / 30 stays in TemporaryRate).
 */
final class GenerationWarnings
{
    public const A_REGISTERED_AS_REPLACEMENT = 'A';

    public const B_UNVERIFIED_DAYS = 'B';

    public const C_COST_NOT_APPROVED = 'C';

    public const D_CALCULATION = 'D';

    /**
     * Conditions A, B and C for the company, read live.
     *
     * @return list<array<string, mixed>>
     */
    public static function live(string $period, ?int $companyId, ?string $companyName): array
    {
        $first = CarbonImmutable::parse($period.'-01')->startOfDay();
        $last = $first->endOfMonth();
        $out = [];

        $unresolved = UnresolvedTemporaryReplacements::query($period)->get();
        $unresolvedCompany = PayrollSections::companyIdsByAssignment($unresolved->pluck('id')->all());

        foreach ($unresolved as $assignment) {
            if (($unresolvedCompany[$assignment->id] ?? null) !== $companyId) {
                continue;
            }

            $out[] = self::entry(self::A_REGISTERED_AS_REPLACEMENT, $companyId, $companyName, [
                'employee_number' => $assignment->employee?->employee_number,
                'employee_name' => $assignment->employee?->full_name,
                'assignment_id' => $assignment->id,
                'detail' => 'Registered as a Replacement although the employee is a Temporary worker (from '.$assignment->start_date->toDateString().').',
                'what_remains' => 'No payroll line is calculated for these days. Correct the employee type, or cancel this Replacement and record Temporary cover; until then the pay is missing from this payroll.',
            ]);
        }

        $covers = TemporaryReplacement::query()
            ->with(['workAssignment', 'workAssignment.employee'])
            ->whereDate('start_date', '<=', $last->toDateString())
            ->where(fn ($q) => $q->whereNull('end_date')->orWhereDate('end_date', '>=', $first->toDateString()))
            ->get();
        $coverCompany = PayrollSections::companyIdsByAssignment($covers->pluck('work_assignment_id')->unique()->all());
        $mine = $covers->filter(fn (TemporaryReplacement $c): bool => ($coverCompany[$c->work_assignment_id] ?? null) === $companyId);

        if ($mine->isNotEmpty()) {
            $unverified = TemporaryReplacementWorkday::query()
                ->whereIn('temporary_replacement_id', $mine->pluck('id')->all())
                ->where('status', TemporaryReplacementWorkday::STATUS_RECORDED)
                ->whereDate('work_date', '>=', $first->toDateString())->whereDate('work_date', '<=', $last->toDateString())
                ->get()->groupBy('temporary_replacement_id');

            foreach ($mine as $cover) {
                $name = $cover->workAssignment?->employee?->full_name;

                if ($unverified->has($cover->id)) {
                    $days = $unverified->get($cover->id)->count();
                    $out[] = self::entry(self::B_UNVERIFIED_DAYS, $companyId, $companyName, [
                        'employee_number' => $cover->workAssignment?->employee?->employee_number,
                        'employee_name' => $name,
                        'replacement_id' => $cover->id,
                        'assignment_id' => $cover->work_assignment_id,
                        'detail' => "Temporary cover #{$cover->id} (covering {$name}) has {$days} workday(s) recorded but not verified in {$period}.",
                        'what_remains' => 'Unverified workdays are not treated as worked: verify them on the Temporary Replacements page, then generate this company again, or the cover pays less than it should.',
                    ]);
                }

                if ($cover->approved_at === null) {
                    $out[] = self::entry(self::C_COST_NOT_APPROVED, $companyId, $companyName, [
                        'employee_number' => $cover->workAssignment?->employee?->employee_number,
                        'employee_name' => $name,
                        'replacement_id' => $cover->id,
                        'assignment_id' => $cover->work_assignment_id,
                        'detail' => "Temporary cover #{$cover->id} (covering {$name}) overlaps {$period} and its cost is not approved.",
                        'what_remains' => 'An unapproved cover is not deducted from the covered employee\'s pay: approve its cost, then generate this company again.',
                    ]);
                }
            }
        }

        return $out;
    }

    /**
     * Condition D: the calculation's Temporary-related warnings that name an assignment of this company.
     *
     * @param  list<string>  $calculatorWarnings
     * @return list<array<string, mixed>>
     */
    public static function fromCalculation(array $calculatorWarnings, ?int $companyId, ?string $companyName): array
    {
        $related = array_values(array_filter($calculatorWarnings, fn (string $w): bool => (bool) preg_match('/temporary|replacement|holder is away|uncovered/i', $w)));
        $ids = [];

        foreach ($related as $index => $warning) {
            if (preg_match_all('/assignment\s*\(?#(\d+)/i', $warning, $m)) {
                $ids[$index] = array_map('intval', $m[1]);
            }
        }

        $company = PayrollSections::companyIdsByAssignment(array_values(array_unique(array_merge([], ...array_values($ids)))));
        $out = [];

        foreach ($related as $index => $warning) {
            foreach ($ids[$index] ?? [] as $assignmentId) {
                if (array_key_exists($assignmentId, $company) && $company[$assignmentId] === $companyId) {
                    $out[] = self::entry(self::D_CALCULATION, $companyId, $companyName, [
                        'assignment_id' => $assignmentId,
                        'detail' => $warning,
                        'what_remains' => 'The payroll calculation reported this for the company; check the Temporary cover and the assignment dates, then generate again if something was corrected.',
                    ]);

                    break;
                }
            }
        }

        return $out;
    }

    /**
     * Everything to show for a company at Review / Finalize: the live A, B and C plus the D entries stored when the company was generated.
     *
     * @param  list<array<string, mixed>>  $stored  the section's stored generation warnings
     * @return list<array<string, mixed>>
     */
    public static function forDisplay(string $period, ?int $companyId, ?string $companyName, array $stored): array
    {
        $storedD = array_values(array_filter($stored, fn (array $w): bool => ($w['condition'] ?? null) === self::D_CALCULATION));

        return [...self::live($period, $companyId, $companyName), ...$storedD];
    }

    /**
     * @param  array<string, mixed>  $fields
     * @return array<string, mixed>
     */
    private static function entry(string $condition, ?int $companyId, ?string $companyName, array $fields): array
    {
        return ['condition' => $condition, 'blocking' => false, 'client_company_id' => $companyId, 'company_name' => $companyName, ...$fields];
    }
}
