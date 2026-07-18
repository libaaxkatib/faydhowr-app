<?php

namespace App\Models;

use App\Enums\Customer\CustomerGender;
use App\Enums\Customer\CustomerStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

#[Fillable([
    'full_name',
    'avatar_url',
    'preferred_language',
    'notification_preferences',
    'gender',
    'date_of_birth',
    'tags',
])]
class CustomerProfile extends Model
{
    use HasFactory, SoftDeletes;

    /**
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * @return HasMany<CustomerAddress, $this>
     */
    public function addresses(): HasMany
    {
        return $this->hasMany(CustomerAddress::class);
    }

    /**
     * @return HasMany<CustomerNote, $this>
     */
    public function notes(): HasMany
    {
        return $this->hasMany(CustomerNote::class);
    }

    /**
     * @return HasMany<CustomerAttachment, $this>
     */
    public function attachments(): HasMany
    {
        return $this->hasMany(CustomerAttachment::class);
    }

    /**
     * @return HasMany<CustomerActivityLog, $this>
     */
    public function activityLogs(): HasMany
    {
        return $this->hasMany(CustomerActivityLog::class);
    }

    /**
     * @return HasOne<Cart, $this>
     */
    public function cart(): HasOne
    {
        return $this->hasOne(Cart::class);
    }

    /**
     * @return HasMany<StoreOrder, $this>
     */
    public function storeOrders(): HasMany
    {
        return $this->hasMany(StoreOrder::class);
    }

    /**
     * @return HasMany<Booking, $this>
     */
    public function bookings(): HasMany
    {
        return $this->hasMany(Booking::class);
    }

    /**
     * @return HasMany<Quotation, $this>
     */
    public function quotations(): HasMany
    {
        return $this->hasMany(Quotation::class);
    }

    /**
     * @return HasMany<Order, $this>
     */
    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /**
     * @return HasMany<Payment, $this>
     */
    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    /**
     * @return HasMany<Review, $this>
     */
    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    /**
     * @param  Builder<CustomerProfile>  $query
     * @return Builder<CustomerProfile>
     */
    public function scopeActiveBusiness(Builder $query): Builder
    {
        return $query->where('status', CustomerStatus::Active->value)->whereNull('deleted_at');
    }

    public function displayStatus(): CustomerStatus
    {
        if ($this->trashed()) {
            return CustomerStatus::Deleted;
        }

        return $this->status ?? CustomerStatus::Active;
    }

    public function canUseCustomerServices(): bool
    {
        return ! $this->trashed() && $this->status === CustomerStatus::Active;
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'notification_preferences' => 'array',
            'tags' => 'array',
            'date_of_birth' => 'date',
            'status' => CustomerStatus::class,
            'gender' => CustomerGender::class,
        ];
    }
}
