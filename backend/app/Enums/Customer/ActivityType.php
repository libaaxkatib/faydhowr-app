<?php

namespace App\Enums\Customer;

enum ActivityType: string
{
    case Registration = 'registration';
    case Login = 'login';
    case ProfileUpdate = 'profile_update';
    case PasswordReset = 'password_reset';
    case AddressAdded = 'address_added';
    case AddressUpdated = 'address_updated';
    case BookingCreated = 'booking_created';
    case BookingUpdated = 'booking_updated';
    case BookingCompleted = 'booking_completed';
    case QuotationRequested = 'quotation_requested';
    case QuotationAccepted = 'quotation_accepted';
    case StoreOrderCreated = 'store_order_created';
    case PaymentRecorded = 'payment_recorded';
    case ReviewSubmitted = 'review_submitted';

    public function label(): string
    {
        return match ($this) {
            self::Registration => 'Registration',
            self::Login => 'Login',
            self::ProfileUpdate => 'Profile Update',
            self::PasswordReset => 'Password Reset',
            self::AddressAdded => 'Address Added',
            self::AddressUpdated => 'Address Updated',
            self::BookingCreated => 'Booking Created',
            self::BookingUpdated => 'Booking Updated',
            self::BookingCompleted => 'Booking Completed',
            self::QuotationRequested => 'Quotation Requested',
            self::QuotationAccepted => 'Quotation Accepted',
            self::StoreOrderCreated => 'Store Order Created',
            self::PaymentRecorded => 'Payment Recorded',
            self::ReviewSubmitted => 'Review Submitted',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
