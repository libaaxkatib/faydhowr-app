<?php

namespace App\Models;

use App\Enums\StoreOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'store_order_id',
    'status',
    'changed_by_type',
    'changed_by_id',
    'notes',
])]
class StoreOrderStatusHistory extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<StoreOrder, $this>
     */
    public function storeOrder(): BelongsTo
    {
        return $this->belongsTo(StoreOrder::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoreOrderStatus::class,
            'changed_by_id' => 'integer',
        ];
    }
}
