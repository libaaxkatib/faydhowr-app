<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use App\Support\Hr\Payroll\PayrollSections;
use App\Support\Hr\Payroll\RecoveryLedger;
use App\Support\Hr\UnresolvedTemporaryReplacements;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Creates (or recomputes) the DRAFT payroll run for a month. There is exactly
 * one run per month. A draft or reviewed run can be regenerated - its lines are
 * rebuilt from the current inputs and any adjustments it had applied go back
 * to pending first - but an approved or finalized run is locked and is never
 * touched. Nothing is paid by generating a run.
 *
 * Company sections: the month is calculated ONCE for everyone (so the rules that look across a person's assignments - a penalty or advance taken
 * once, absence charged once, recovery spread over the lines, a position's cents rounded together - stay exactly as they were), but only the sections
 * that are NOT protected are written. A company whose section is approved or finalized keeps its stored lines untouched, whatever the live
 * calculation says now (a difference is reported as a warning: a change after approval is handled by a payroll correction, never by regenerating).
 * Companies that are draft or reviewed are rebuilt, and a reviewed one goes back to draft, as a regenerated run always did.
 */
class GeneratePayrollRunAction
{
    public function __construct(private readonly PayrollRunCalculator $calculator = new PayrollRunCalculator) {}

    public function handle(string $period, Admin $actor): PayrollRun
    {
        return DB::transaction(function () use ($period, $actor) {
            $run = PayrollRun::query()->where('period', $period)->lockForUpdate()->first()
                ?? PayrollRun::query()->create(['period' => $period, 'status' => PayrollRun::STATUS_DRAFT, 'currency' => PayrollMoney::CURRENCY]);

            if (! $run->isEditable()) {
                throw new HttpResponseException(ApiResponse::error(
                    "Payroll for {$period} is {$run->status} and can no longer be regenerated. Reopen a company's payroll for correction (Super Admin or HR Manager, with a reason) or use a payroll adjustment.",
                    'PAYROLL_RUN_LOCKED',
                    422,
                ));
            }

            // A Temporary worker confirmed as a Replacement but still classified as Temporary has no payroll line and must be corrected first:
            // the month is not calculated until HR fixes the record (nothing is converted automatically). The rollback also removes a draft just created.
            $unresolved = UnresolvedTemporaryReplacements::query($period)->get();

            if ($unresolved->isNotEmpty()) {
                throw new HttpResponseException(ApiResponse::error(
                    "Payroll for {$period} cannot be calculated yet: ".$unresolved->count().' record(s) register a Temporary worker as a Replacement ('.UnresolvedTemporaryReplacements::describe($unresolved).'). '.UnresolvedTemporaryReplacements::REASON,
                    'PAYROLL_UNRESOLVED_TEMPORARY_REPLACEMENT',
                    422,
                    ['assignments' => $unresolved->map(fn ($a): array => ['assignment_id' => $a->id, 'employee_number' => $a->employee?->employee_number, 'employee_name' => $a->employee?->full_name, 'start_date' => $a->start_date->toDateString(), 'end_date' => $a->end_date?->toDateString()])->all()],
                ));
            }

            $sections = $run->sections()->with('clientCompany')->get()->keyBy(fn (PayrollRunSection $s): string => $this->key($s->client_company_id));
            $protected = $sections->filter(fn (PayrollRunSection $s): bool => $s->isLocked());
            $protectedIds = $protected->pluck('id')->all();

            // Only the lines that are not in a protected section are rebuilt.
            $itemIds = $run->items()
                ->when($protectedIds !== [], fn ($q) => $q->where(fn ($w) => $w->whereNull('section_id')->orWhereNotIn('section_id', $protectedIds)))
                ->pluck('id');
            PayrollAdjustment::query()->whereIn('applied_in_item_id', $itemIds)->update(['status' => 'pending', 'applied_in_item_id' => null]);
            // One statement for every line (it was one per line: a query-by-query delete of ~300 lines costs seconds on production's network).
            PayrollItemDeduction::query()->whereIn('payroll_item_id', $itemIds)->delete();
            PayrollItem::query()->whereIn('id', $itemIds)->delete();

            $result = $this->calculator->calculate($period);
            $companyOf = PayrollSections::companyIdsByAssignment(array_values(array_unique(array_column($result['items'], 'work_assignment_id'))));

            $write = [];
            $skipped = [];

            foreach ($result['items'] as $line) {
                $key = $this->key($companyOf[$line['work_assignment_id']] ?? null);

                if ($protected->has($key)) {
                    $skipped[$line['work_assignment_id']] = (int) $line['net_cents'];

                    continue;
                }

                $write[$key][] = $line;
            }

            // Sections of companies that no longer have a line (and are not protected) go; the ones that remain start again as draft.
            foreach ($sections as $key => $section) {
                if (! $section->isLocked() && ! isset($write[$key])) {
                    $section->delete();
                    $sections->forget($key);
                }
            }

            foreach ($write as $key => $lines) {
                $fields = ['status' => PayrollRunSection::STATUS_DRAFT, 'reviewed_at' => null, 'reviewed_by' => null, 'approved_at' => null, 'approved_by' => null, 'finalized_at' => null, 'finalized_by' => null, 'totals' => $this->totals($lines)];
                $section = $sections->get($key);

                if ($section === null) {
                    $section = PayrollRunSection::query()->create(['payroll_run_id' => $run->id, 'client_company_id' => $key === 'none' ? null : (int) $key, ...$fields]);
                    $sections->put($key, $section);
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
            }

            $warnings = $result['warnings'];
            $totals = $result['totals'];

            if ($protected->isNotEmpty()) {
                $warnings = [...$warnings, ...$this->protectedWarnings($protected, $skipped)];
                $totals = $this->storedTotals($run);
            }

            RecoveryLedger::syncFromRun($run);

            $run->update([
                'status' => PayrollRun::STATUS_DRAFT,
                'generated_at' => now(), 'generated_by' => $actor->id,
                'reviewed_at' => null, 'reviewed_by' => null,
                'totals' => [...$totals, 'warnings' => $warnings],
            ]);
            PayrollSections::syncRun($run);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Payroll draft for {$period} generated ({$totals['item_count']} line(s)".($protected->isNotEmpty() ? ', '.$protected->count().' approved/finalized company section(s) kept as they were' : '').').',
                entityType: PayrollRun::class,
                entityId: $run->id,
                metadata: ['totals' => $totals, 'warnings' => count($warnings), 'protected_sections' => $protected->count()],
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
     * The month's totals as stored (the lines kept from protected companies plus the ones just written), not the live calculation.
     *
     * @return array<string, int>
     */
    private function storedTotals(PayrollRun $run): array
    {
        $row = DB::table('payroll_items')->where('payroll_run_id', $run->id)->selectRaw(
            'count(*) as item_count, coalesce(sum(gross_cents), 0) as gross_cents, coalesce(sum(replacement_deduction_cents), 0) as replacement_deduction_cents, coalesce(sum(other_deduction_cents), 0) as other_deduction_cents, coalesce(sum(adjustment_cents), 0) as adjustment_cents, coalesce(sum(net_cents), 0) as net_cents',
        )->first();

        return array_map('intval', (array) $row);
    }

    /**
     * A protected company's stored lines are never rebuilt. When today's calculation would give something else for them (an assignment or an input
     * changed after approval) HR is told: it is handled by a payroll correction, never by regenerating.
     *
     * @param  Collection<string, PayrollRunSection>  $protected
     * @param  array<int, int>  $skipped  work assignment id => net cents of today's calculation
     * @return list<string>
     */
    private function protectedWarnings($protected, array $skipped): array
    {
        $stored = PayrollItem::query()->whereIn('section_id', $protected->pluck('id')->all())->pluck('net_cents', 'work_assignment_id')->map(fn ($v): int => (int) $v)->all();
        $differs = [];

        $storedBySection = PayrollItem::query()->whereIn('section_id', $protected->pluck('id')->all())->get(['section_id', 'work_assignment_id'])->groupBy('section_id');

        foreach ($protected as $section) {
            $ids = ($storedBySection->get($section->id) ?? collect())->pluck('work_assignment_id')->all();
            $count = count(array_filter($ids, fn ($id): bool => ($skipped[$id] ?? null) !== ($stored[$id] ?? null)));

            if ($count > 0) {
                $differs[] = ($section->clientCompany?->name ?? 'No company').' ('.$count.')';
            }
        }

        $newOnes = count(array_filter(array_keys($skipped), fn ($id): bool => ! array_key_exists($id, $stored)));

        if ($differs === [] && $newOnes === 0) {
            return [];
        }

        return ['Approved or finalized company payroll was kept exactly as stored. Today\'s calculation differs from it'.($differs !== [] ? ' for '.implode(', ', array_slice($differs, 0, 8)).(count($differs) > 8 ? ' and others' : '') : '').($newOnes > 0 ? ($differs !== [] ? ' and' : '').' has '.$newOnes.' line(s) that are not in it' : '').'. Nothing was changed: record a payroll correction for these.'];
    }
}
