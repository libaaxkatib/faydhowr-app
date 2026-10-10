<?php

namespace App\Actions\Hr;

use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\PayrollAdjustment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The only way to change money in a finalized month. A correction (signed
 * amount: positive pays more, negative recovers) or a full reversal of the
 * item's net pay is recorded against the finalized item and applied by the
 * run of a LATER month; the finalized month itself is never edited. A item can
 * be reversed only once.
 */
class CreatePayrollAdjustmentAction
{
    public function handle(PayrollItem $item, string $kind, ?string $amount, string $reason, string $appliesInPeriod, Admin $actor): PayrollAdjustment
    {
        $item->loadMissing('run');

        if ($item->run->status !== PayrollRun::STATUS_FINALIZED) {
            $this->fail('Adjustments are only for finalized payroll; edit a draft or reopen an approved run instead.', 'PAYROLL_RUN_NOT_FINALIZED');
        }

        if (strcmp($appliesInPeriod, $item->period) <= 0) {
            $this->fail('An adjustment must apply in a payroll month after the one it corrects.', 'ADJUSTMENT_PERIOD_NOT_LATER');
        }

        if (PayrollRun::query()->where('period', $appliesInPeriod)->whereIn('status', ['approved', 'finalized'])->exists()) {
            $this->fail("Payroll {$appliesInPeriod} is already approved or finalized; choose a later month.", 'ADJUSTMENT_PERIOD_LOCKED');
        }

        if ($kind === PayrollAdjustment::KIND_REVERSAL) {
            if ($item->adjustments()->where('kind', PayrollAdjustment::KIND_REVERSAL)->exists()) {
                $this->fail('This payroll line has already been reversed.', 'PAYROLL_ITEM_ALREADY_REVERSED');
            }

            $cents = -$item->net_cents;
        } else {
            $cents = PayrollMoney::toCents($amount ?? 0);

            if ($cents === 0) {
                $this->fail('A correction needs a non-zero amount.', 'ADJUSTMENT_AMOUNT_REQUIRED');
            }
        }

        $adjustment = PayrollAdjustment::query()->create([
            'payroll_item_id' => $item->id, 'kind' => $kind, 'amount_cents' => $cents, 'reason' => $reason,
            'applies_in_period' => $appliesInPeriod, 'status' => 'pending', 'created_by' => $actor->id,
        ]);

        event(AuditEvent::record(
            action: AuditAction::Update,
            admin: $actor,
            description: ucfirst($kind)." recorded against finalized payroll {$item->period} line #{$item->id}, to apply in {$appliesInPeriod}.",
            entityType: PayrollItem::class,
            entityId: $item->id,
            metadata: ['adjustment_id' => $adjustment->id, 'amount_cents' => $cents, 'reason' => $reason],
        ));

        return $adjustment;
    }

    private function fail(string $message, string $code): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422));
    }
}
