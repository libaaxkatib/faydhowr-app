<?php

namespace App\Enums;

enum PaymentMethod: string
{
    case EvcPlus = 'evc_plus';
    case Edahab = 'edahab';
    case BankTransfer = 'bank_transfer';
    case CashOnDelivery = 'cash_on_delivery';
    case CashOnService = 'cash_on_service';

    /**
     * Prepaid methods settle before fulfilment (store orders).
     */
    public function isPrepaid(): bool
    {
        return in_array($this, [self::EvcPlus, self::Edahab, self::BankTransfer], true);
    }
}
