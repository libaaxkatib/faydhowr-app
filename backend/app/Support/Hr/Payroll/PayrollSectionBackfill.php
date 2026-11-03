<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use Illuminate\Support\Facades\DB;

/**
 * Links the EXISTING payroll lines of draft / reviewed runs to their company sections, without recalculating or regenerating anything.
 *
 *  - The company of a line is the one its assignment's relationship gives (assignment -> client company, else assignment -> work location -> client
 *    company); never the text label stored on the line. A line whose assignment names no company at all goes to the run's "no company" section.
 *  - Only payroll_items.section_id is written (with a query that does not even touch updated_at). No amount, deduction, payment status, employee or
 *    assignment changes; ids are preserved.
 *  - Approved and finalized runs are not touched: they keep their run-level behaviour.
 *  - Idempotent: a line that already has a section is left alone, a section that exists is reused, so running it again changes nothing.
 *  - Every line is reported: linked now, already linked, and the ones that could not be linked (a line whose assignment no longer exists) - those are
 *    left unlinked and listed, never guessed.
 */
final class PayrollSectionBackfill
{
    /**
     * @return array{runs: int, sections_created: int, lines_linked: int, lines_already_linked: int, lines_to_no_company_section: int, unresolved: list<int>}
     */
    public static function run(bool $dryRun = false): array
    {
        $report = ['runs' => 0, 'sections_created' => 0, 'lines_linked' => 0, 'lines_already_linked' => 0, 'lines_to_no_company_section' => 0, 'unresolved' => []];

        $runs = PayrollRun::query()->whereIn('status', [PayrollRun::STATUS_DRAFT, PayrollRun::STATUS_REVIEWED])->orderBy('id')->get();

        foreach ($runs as $run) {
            $report['runs']++;
            $report['lines_already_linked'] += DB::table('payroll_items')->where('payroll_run_id', $run->id)->whereNotNull('section_id')->count();

            $lines = DB::table('payroll_items')->where('payroll_run_id', $run->id)->whereNull('section_id')->orderBy('id')->get(['id', 'work_assignment_id']);

            if ($lines->isEmpty()) {
                continue;
            }

            $companies = PayrollSections::companyIdsByAssignment($lines->pluck('work_assignment_id')->map(fn ($id): int => (int) $id)->unique()->values()->all());
            $byCompany = [];

            foreach ($lines as $line) {
                $assignmentId = (int) $line->work_assignment_id;

                if (! array_key_exists($assignmentId, $companies)) {
                    $report['unresolved'][] = (int) $line->id;

                    continue;
                }

                $byCompany[$companies[$assignmentId] ?? 'none'][] = (int) $line->id;
            }

            foreach ($byCompany as $companyKey => $ids) {
                $companyId = $companyKey === 'none' ? null : (int) $companyKey;

                if ($companyId === null) {
                    $report['lines_to_no_company_section'] += count($ids);
                }

                $report['lines_linked'] += count($ids);

                if ($dryRun) {
                    continue;
                }

                $section = PayrollRunSection::query()->where('payroll_run_id', $run->id)->when($companyId === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $companyId))->first();

                if ($section === null) {
                    // The section starts where the run is: draft, or reviewed with the run's own reviewer and date.
                    $section = PayrollRunSection::query()->create([
                        'payroll_run_id' => $run->id,
                        'client_company_id' => $companyId,
                        'status' => $run->status,
                        'reviewed_at' => $run->status === PayrollRun::STATUS_REVIEWED ? $run->reviewed_at : null,
                        'reviewed_by' => $run->status === PayrollRun::STATUS_REVIEWED ? $run->reviewed_by : null,
                    ]);
                    $report['sections_created']++;
                }

                foreach (array_chunk($ids, 500) as $chunk) {
                    // Query builder on purpose: only section_id is written, not even updated_at.
                    DB::table('payroll_items')->whereIn('id', $chunk)->whereNull('section_id')->update(['section_id' => $section->id]);
                }
            }

            if (! $dryRun) {
                foreach ($run->sections()->get() as $section) {
                    PayrollSections::refreshTotals($section);
                }
            }
        }

        return $report;
    }
}
