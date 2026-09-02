<?php

namespace App\Models;

use App\Enums\Marketing\MarketingQuotationStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['marketing_record_id', 'amount', 'sent_at', 'status', 'notes', 'created_by'])]
class MarketingQuotation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'sent_at' => 'date',
            'status' => MarketingQuotationStatus::class,
        ];
    }

    public function marketingRecord(): BelongsTo
    {
        return $this->belongsTo(MarketingRecord::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
