<?php

namespace App\Models;

use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'order_id',
    'status',
    'changed_by_type',
    'changed_by_id',
    'notes',
])]
class OrderStatusHistory extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Order, $this>
     */
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'changed_by_id' => 'integer',
        ];
    }
}
