<?php

namespace App\Observers\Customer;

use App\Contracts\Customer\Services\CustomerActivityServiceInterface;
use App\Enums\Customer\ActivityType;
use App\Models\Booking;
use App\Models\CustomerProfile;
use App\Models\Payment;
use App\Models\Quotation;
use App\Models\StoreOrder;
use Illuminate\Database\Eloquent\Model;

/**
 * Records customer timeline events for commercial domain models.
 */
class CustomerCommercialActivityObserver
{
    public function __construct(private CustomerActivityServiceInterface $activities) {}

    public function created(Model $model): void
    {
        $profile = $this->profileFor($model);

        if ($profile === null) {
            return;
        }

        $type = match (true) {
            $model instanceof Booking => ActivityType::BookingCreated,
            $model instanceof Quotation => ActivityType::QuotationRequested,
            $model instanceof StoreOrder => ActivityType::StoreOrderCreated,
            $model instanceof Payment => ActivityType::PaymentRecorded,
            default => null,
        };

        if ($type === null) {
            return;
        }

        $this->activities->record($profile, $type, $type->label(), $model);
    }

    public function updated(Model $model): void
    {
        $profile = $this->profileFor($model);

        if ($profile === null) {
            return;
        }

        if ($model instanceof Booking) {
            $status = strtolower((string) ($model->status?->value ?? $model->status ?? ''));

            if (str_contains($status, 'complet')) {
                $this->activities->record($profile, ActivityType::BookingCompleted, ActivityType::BookingCompleted->label(), $model);

                return;
            }

            $this->activities->record($profile, ActivityType::BookingUpdated, ActivityType::BookingUpdated->label(), $model);

            return;
        }

        if ($model instanceof Quotation) {
            $status = strtolower((string) ($model->status?->value ?? $model->status ?? ''));

            if (str_contains($status, 'accept')) {
                $this->activities->record($profile, ActivityType::QuotationAccepted, ActivityType::QuotationAccepted->label(), $model);
            }
        }
    }

    private function profileFor(Model $model): ?CustomerProfile
    {
        $profileId = $model->getAttribute('customer_profile_id');

        if ($profileId === null) {
            return null;
        }

        return CustomerProfile::query()->find($profileId);
    }
}
