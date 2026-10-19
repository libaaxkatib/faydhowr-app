<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollItemDeduction;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use App\Support\Hr\Payroll\PayrollRunCalculator;
use App\Support\Hr\Payroll\RecoveryLedger;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * Creates (or recomputes) the DRAFT payroll run for a month. There is exactly
 * one run per month. A draft or reviewed run can be regenerated - its lines are
 * rebuilt from the current inputs and any adjustments it had applied go back
 * to pending first - but an approved or finalized run is locked and is never
 * touched. Nothing is paid by generating a run.
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
                    "Payroll for {$period} is {$run->status} and can no longer be regenerated. Use a payroll adjustment for corrections.",
                    'PAYROLL_RUN_LOCKED',
                    422,
                ));
            }

            $itemIds = $run->items()->pluck('id');
            PayrollAdjustment::query()->whereIn('applied_in_item_id', $itemIds)->update(['status' => 'pending', 'applied_in_item_id' => null]);
            PayrollItem::query()->whereIn('id', $itemIds)->each(fn (PayrollItem $item) => $item->deductions()->delete());
            $run->items()->delete();

            $result = $this->calculator->calculate($period);

            foreach ($result['items'] as $line) {
                $item = PayrollItem::query()->create([
                    'payroll_run_id' => $run->id,
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

            RecoveryLedger::syncFromRun($run);

            $run->update([
                'status' => PayrollRun::STATUS_DRAFT,
                'generated_at' => now(), 'generated_by' => $actor->id,
                'reviewed_at' => null, 'reviewed_by' => null,
                'totals' => [...$result['totals'], 'warnings' => $result['warnings']],
            ]);

            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Payroll draft for {$period} generated ({$result['totals']['item_count']} line(s)).",
                entityType: PayrollRun::class,
                entityId: $run->id,
                metadata: ['totals' => $result['totals'], 'warnings' => count($result['warnings'])],
            ));

            return $run->refresh();
        });
    }
}
