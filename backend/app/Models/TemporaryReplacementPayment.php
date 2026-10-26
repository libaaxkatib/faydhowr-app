<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['temporary_replacement_id', 'payment_date', 'amount', 'calculated_amount', 'notes', 'override_reason', 'calculation', 'paid_by'])]
class TemporaryReplacementPayment extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'payment_date' => 'date',
            'amount' => 'decimal:2',
            'calculated_amount' => 'decimal:2',
            'calculation' => 'array',
        ];
    }

    public function temporaryReplacement(): BelongsTo
    {
        return $this->belongsTo(TemporaryReplacement::class);
    }

    public function paidBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'paid_by');
    }
}
