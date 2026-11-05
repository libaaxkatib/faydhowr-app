<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\DeductionInputsFingerprint;
use App\Support\Hr\Payroll\GenerationWarnings;
use App\Support\Hr\Payroll\PayrollCompanyOverview;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use App\Support\Hr\Payroll\PayrollSections;
use App\Support\Hr\Payroll\RecoveryLedger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Generates (or recomputes) the DRAFT payroll of ONE company for a month (owner decisions N-2, N-2a, D-3). There is no month-wide Generate: each company
 * is generated on its own, and nothing is paid by generating.
 *
 * The month is still CALCULATED once for everyone, so the rules that look across a person's assignments (a penalty or advance taken once, absence charged
 * once per company, recovery limited to the company that was overpaid, a position's cents rounded together) give exactly the same lines as before - but only
 * the selected company's section and lines are written. Nothing of another company is touched: not its lines, its section, its adjustments, its payments.
 *
 *  - A company whose section is approved or finalized is refused (its payroll is corrected with a Payroll Correction, or reopened for correction); this is
 *    what keeps the 112 locked November sections out of reach.
 *  - A company with nothing payable keeps a visible empty Draft section ("Generated - Nothing Payable", D-3).
 *  - Temporary Replacement situations are WARNINGS ONLY (N-2b): they are stored on the section and never block the generation.
 *  - The run row is locked first and the section second (the same order the transitions and payments use), so two requests are decided one after the other;
 *    $expectedStatus turns a stale screen into a 409 instead of silently resetting a section that was reviewed in the meantime.
 */
class GenerateCompanyPayrollAction
{
    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    /**
     * @param  ?string  $expectedStatus  the section status the screen showed (null = none shown yet); another status answers 409
     */
    public function handle(string $period, ?int $companyId, Admin $actor, ?string $expectedStatus = null): PayrollRun
    {
        return DB::transaction(function () use ($period, $companyId, $actor, $expectedStatus): PayrollRun {
            $company = $companyId === null ? null : ClientCompany::query()->find($companyId);

            if ($companyId !== null && $company === null) {
                $this->fail('This company does not exist. Nothing was generated.', 'COMPANY_NOT_FOUND', 404);
            }

            $run = PayrollRun::query()->where('period', $period)->lockForUpdate()->first()
                ?? PayrollRun::query()->create(['period' => $period, 'status' => PayrollRun::STATUS_DRAFT, 'currency' => PayrollMoney::CURRENCY]);

            $hasSections = $run->sections()->exists();

            // A run from before company sections is one block: it can only be regenerated while it is draft or reviewed, as before.
            if (! $hasSections && ! $run->isEditable()) {
                $this->fail("Payroll for {$period} is {$run->status} and can no longer be regenerated. Use a payroll correction or a payroll adjustment.", 'PAYROLL_RUN_LOCKED', 422);
            }

            $key = $companyId === null ? 'none' : (string) $companyId;
            $section = $run->sections()
                ->when($companyId === null, fn ($q) => $q->whereNull('client_company_id'), fn ($q) => $q->where('client_company_id', $companyId))
                ->lockForUpdate()->first();
            $name = $company?->name ?? 'No company';

            if ($section !== null && $section->isLocked()) {
                $this->fail("{$name} is {$section->status} for {$period}: its payroll is not regenerated. Record a Payroll Correction for a change, or ask a Super Admin to reopen it for correction.", 'PAYROLL_COMPANY_LOCKED', 422);
            }

            if ($section !== null && $expectedStatus !== null && $section->status !== $expectedStatus) {
                $this->fail("{$name} changed while this screen was open (it is now {$section->status}). Nothing was generated; refresh and try again.", 'PAYROLL_SECTION_CHANGED', 409);
            }

            if ($section === null && ! isset(PayrollCompanyOverview::assignedCompanyIds($period)[$companyId ?? 0])) {
                $this->fail("{$name} has no assignment in {$period}, so there is no payroll to generate. Nothing was generated.", 'COMPANY_NOT_ASSIGNED_IN_PERIOD', 422);
            }

            $previousStatus = $section?->status;

            // Only this company's lines are rebuilt, and only the adjustments applied in THEM go back to pending first (BEFORE the calculation, which reads the pending adjustments).
            if ($section !== null) {
                $itemIds = $section->items()->pluck('id');
                PayrollAdjustment::query()->whereIn('applied_in_item_id', $itemIds)->update(['status' => 'pending', 'applied_in_item_id' => null]);
                PayrollItemDeduction::query()->whereIn('payroll_item_id', $itemIds)->delete();
                PayrollItem::query()->whereIn('id', $itemIds)->delete();
            }

            // The month is calculated ONCE for everyone (cross-assignment rules); only this company's lines are written.
            $result = $this->calculator->calculate($period);
            $companyOf = PayrollSections::companyIdsByAssignment(array_values(array_unique(array_column($result['items'], 'work_assignment_id'))));
            $lines = array_values(array_filter($result['items'], fn (array $line): bool => $this->key($companyOf[$line['work_assignment_id']] ?? null) === $key));

            $warnings = [
                ...GenerationWarnings::live($period, $companyId, $company?->name),
                ...GenerationWarnings::fromCalculation($result['warnings'], $companyId, $company?->name),
            ];

            $fields = [
                'status' => PayrollRunSection::STATUS_DRAFT,
                'reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null, 'finalized_at' => null, 'finalized_by' => null,
                'totals' => $this->totals($lines),
                'generated_at' => now(), 'generated_by' => $actor->id,
                'generation_warnings' => $warnings,
            ];

            if ($section === null) {
                $section = PayrollRunSection::query()->create(['payroll_run_id' => $run->id, 'client_company_id' => $companyId, ...$fields]);
            } else {
                $section->update($fields);
            }

            foreach ($lines as $line) {
                $item = PayrollItem::query()->create([
                    'payroll_run_id' => $run->id,
                    'section_id' => $section->id,
                    'period' => $period,
                    ...collect($line)->except(['deductions', 'adjustment_ids'])->all(),
                ]);

                foreach ($line['deductions'] as $deduction) {
                    PayrollItemDeduction::query()->create([...$deduction, 'payroll_item_id' => $item->id, 'period' => $period]);
                }

                if ($line['adjustment_ids'] !== []) {
                    PayrollAdjustment::query()->whereIn('id', $line['adjustment_ids'])->update(['status' => 'applied', 'applied_in_item_id' => $item->id]);
                }
            }

            // Freshness guard (Release 1B): remember which penalties / advances these lines were calculated from.
            $section->update(['deduction_inputs_hash' => DeductionInputsFingerprint::forSection($section, $period)]);

            RecoveryLedger::syncFromRun($run);

            // The run's own totals are what is stored now (every company's lines); its warnings are the calculation's (the month was calculated for everyone, so
            // ownership / currency / no-line warnings of ANY company are reported, as before) plus the Temporary Replacement warnings of this company.
            $stored = $this->storedTotals($run);
            $runWarnings = array_values(array_unique([
                ...$result['warnings'],
                ...array_map(fn (array $w): string => trim(($w['company_name'] ?? 'No company').': '.($w['detail'] ?? '')), array_filter($warnings, fn (array $w): bool => $w['condition'] !== GenerationWarnings::D_CALCULATION)),
            ]));
            $run->update([
                'generated_at' => now(), 'generated_by' => $actor->id,
                'totals' => [...$stored, 'warnings' => $runWarnings],
            ]);
            PayrollSections::syncRun($run);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Payroll draft for {$name}, {$period} generated (".count($lines).' line(s)'.($lines === [] ? ' - nothing payable' : '').($warnings !== [] ? ', '.count($warnings).' warning(s)' : '').').',
                entityType: PayrollRunSection::class,
                entityId: $section->id,
                metadata: [
                    'event' => 'payroll_company_generated',
                    'payroll_run_id' => $run->id,
                    'period' => $period,
                    'client_company_id' => $companyId,
                    'company' => $name,
                    'previous_status' => $previousStatus,
                    'lines' => count($lines),
                    'totals' => $section->totals,
                    'warnings' => array_map(fn (array $w): array => ['condition' => $w['condition'], 'assignment_id' => $w['assignment_id'] ?? null, 'employee_number' => $w['employee_number'] ?? null], $warnings),
                ],
            ));

            return $run->refresh();
        });
    }

    private function key(?int $companyId): string
    {
        return $companyId === null ? 'none' : (string) $companyId;
    }

    /**
     * @param  list<array<string, mixed>>  $lines
     * @return array<string, int>
     */
    private function totals(array $lines): array
    {
        $sum = fn (string $key): int => (int) array_sum(array_column($lines, $key));

        return [
            'item_count' => count($lines),
            'employee_count' => count(array_unique(array_column($lines, 'employee_id'))),
            'gross_cents' => $sum('gross_cents'),
            'replacement_deduction_cents' => $sum('replacement_deduction_cents'),
            'other_deduction_cents' => $sum('other_deduction_cents'),
            'adjustment_cents' => $sum('adjustment_cents'),
            'net_cents' => $sum('net_cents'),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function storedTotals(PayrollRun $run): array
    {
        $row = DB::table('payroll_items')->where('payroll_run_id', $run->id)->selectRaw(
            'count(*) as item_count, coalesce(sum(gross_cents), 0) as gross_cents, coalesce(sum(replacement_deduction_cents), 0) as replacement_deduction_cents, coalesce(sum(other_deduction_cents), 0) as other_deduction_cents, coalesce(sum(adjustment_cents), 0) as adjustment_cents, coalesce(sum(net_cents), 0) as net_cents',
        )->first();

        return array_map('intval', (array) $row);
    }

    private function fail(string $message, string $code, int $status): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, $status));
    }
}
