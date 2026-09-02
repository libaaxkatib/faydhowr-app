<?php

namespace App\Models;

use App\Enums\Marketing\CommissionStatus;
use App\Enums\Marketing\MarketingRecordType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admin_id', 'marketing_record_id', 'type', 'reference_date', 'commission_rate_id', 'amount', 'status', 'notes'])]
class CommissionRecord extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'type' => MarketingRecordType::class,
            'reference_date' => 'date',
            'amount' => 'decimal:2',
            'status' => CommissionStatus::class,
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function marketingRecord(): BelongsTo
    {
        return $this->belongsTo(MarketingRecord::class);
    }

    public function rate(): BelongsTo
    {
        return $this->belongsTo(CommissionRate::class, 'commission_rate_id');
    }
}
