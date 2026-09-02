<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['marketing_record_id', 'facility_name', 'manager_name', 'manager_title', 'phone', 'location', 'needs'])]
class XarunDetail extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return ['needs' => 'array'];
    }

    public function marketingRecord(): BelongsTo
    {
        return $this->belongsTo(MarketingRecord::class);
    }
}
