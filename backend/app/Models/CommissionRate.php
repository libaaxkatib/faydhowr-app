<?php

namespace App\Models;

use App\Enums\Marketing\CommissionRateType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['admin_id', 'rate_type', 'rate_value', 'effective_from', 'created_by'])]
class CommissionRate extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'rate_type' => CommissionRateType::class,
            'rate_value' => 'decimal:4',
            'effective_from' => 'date',
        ];
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
