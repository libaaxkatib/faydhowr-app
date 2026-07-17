<?php

namespace App\DataTransferObjects\Settings;

use App\Contracts\Settings\SettingsCategoryValuesInterface;

final readonly class NumberingSettingsData implements SettingsCategoryValuesInterface
{
    public function __construct(
        public ?string $customerPrefix,
        public ?string $bookingPrefix,
        public ?string $quotationPrefix,
        public ?string $invoicePrefix,
        public ?string $receiptPrefix,
        public ?string $orderPrefix,
        public ?string $paymentPrefix,
        public ?bool $autoNumbering,
    ) {}

    public static function fromValues(array $values): static
    {
        return new self(
            customerPrefix: $values['customer_prefix'] ?? null,
            bookingPrefix: $values['booking_prefix'] ?? null,
            quotationPrefix: $values['quotation_prefix'] ?? null,
            invoicePrefix: $values['invoice_prefix'] ?? null,
            receiptPrefix: $values['receipt_prefix'] ?? null,
            orderPrefix: $values['order_prefix'] ?? null,
            paymentPrefix: $values['payment_prefix'] ?? null,
            autoNumbering: isset($values['auto_numbering']) ? (bool) $values['auto_numbering'] : null,
        );
    }

    public function toArray(): array
    {
        return [
            'numbering.customer_prefix' => $this->customerPrefix,
            'numbering.booking_prefix' => $this->bookingPrefix,
            'numbering.quotation_prefix' => $this->quotationPrefix,
            'numbering.invoice_prefix' => $this->invoicePrefix,
            'numbering.receipt_prefix' => $this->receiptPrefix,
            'numbering.order_prefix' => $this->orderPrefix,
            'numbering.payment_prefix' => $this->paymentPrefix,
            'numbering.auto_numbering' => $this->autoNumbering,
        ];
    }
}
