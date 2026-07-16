<?php

namespace App\Models;

use App\Enums\StoreOrderStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'store_order_number',
    'customer_profile_id',
    'cart_id',
    'customer_address_id',
    'status',
    'currency',
    'total_items',
    'total_quantity',
    'subtotal',
    'shipping_address_snapshot',
    'notes',
    'cancelled_at',
    'cancellation_reason',
])]
class StoreOrder extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return BelongsTo<Cart, $this>
     */
    public function cart(): BelongsTo
    {
        return $this->belongsTo(Cart::class);
    }

    /**
     * @return BelongsTo<CustomerAddress, $this>
     */
    public function customerAddress(): BelongsTo
    {
        return $this->belongsTo(CustomerAddress::class);
    }

    /**
     * @return HasMany<StoreOrderItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(StoreOrderItem::class);
    }

    /**
     * @return HasMany<StoreOrderStatusHistory, $this>
     */
    public function statusHistories(): HasMany
    {
        return $this->hasMany(StoreOrderStatusHistory::class);
    }

    /**
     * @return MorphMany<Payment, $this>
     */
    public function payments(): MorphMany
    {
        return $this->morphMany(Payment::class, 'payable');
    }

    /**
     * @return MorphMany<StockLedger, $this>
     */
    public function stockLedgers(): MorphMany
    {
        return $this->morphMany(StockLedger::class, 'reference');
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'status' => StoreOrderStatus::class,
            'total_items' => 'integer',
            'total_quantity' => 'integer',
            'subtotal' => 'decimal:2',
            'shipping_address_snapshot' => 'array',
            'cancelled_at' => 'datetime',
        ];
    }
}
