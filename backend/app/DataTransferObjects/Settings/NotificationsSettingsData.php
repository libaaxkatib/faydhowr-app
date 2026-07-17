<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class NotificationsSettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?bool $email,
        public ?bool $browser,
        public ?bool $bookingAlerts,
        public ?bool $quotationAlerts,
        public ?bool $paymentAlerts,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            email: isset($values['email']) ? (bool) $values['email'] : null,
            browser: isset($values['browser']) ? (bool) $values['browser'] : null,
            bookingAlerts: isset($values['booking_alerts']) ? (bool) $values['booking_alerts'] : null,
            quotationAlerts: isset($values['quotation_alerts']) ? (bool) $values['quotation_alerts'] : null,
            paymentAlerts: isset($values['payment_alerts']) ? (bool) $values['payment_alerts'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'notifications.email' => $this->email,
            'notifications.browser' => $this->browser,
            'notifications.booking_alerts' => $this->bookingAlerts,
            'notifications.quotation_alerts' => $this->quotationAlerts,
            'notifications.payment_alerts' => $this->paymentAlerts,
        ];
    }
}
