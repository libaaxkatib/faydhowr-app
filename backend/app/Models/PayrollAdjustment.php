<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['payroll_item_id', 'kind', 'amount_cents', 'reason', 'applies_in_period', 'status', 'applied_in_item_id', 'created_by'])]
class PayrollAdjustment extends Model
{
    public const KIND_CORRECTION = 'correction';

    public const KIND_REVERSAL = 'reversal';

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayrollItem::class, 'payroll_item_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
