<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payroll_item_id', 'period', 'kind', 'source_type', 'source_id', 'amount_cents', 'description', 'detail'])]
class PayrollItemDeduction extends Model
{
    public const KIND_REPLACEMENT = 'replacement';

    public const KIND_PENALTY = 'penalty';

    public const KIND_ADVANCE = 'advance';

    public const KIND_ABSENCE = 'absence';

    public const KIND_ADJUSTMENT = 'adjustment';

    public const KIND_UNPAID_LEAVE = 'unpaid_leave';

    /** A monthly instalment of an overpayment found in an earlier closed month (see PayrollCorrection). */
    public const KIND_OVERPAYMENT_RECOVERY = 'overpayment_recovery';

    protected function casts(): array
    {
        return ['detail' => 'array'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }
}
