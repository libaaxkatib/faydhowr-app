<?php

namespace App\Enums;

enum PaymentStatus: string
{
    case Pending = 'pending';
    case Initialized = 'initialized';
    case Processing = 'processing';
    case Paid = 'paid';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function isActive(): bool
    {
        return in_array($this, [self::Pending, self::Initialized, self::Processing], true);
    }

    public function isTerminal(): bool
    {
        return in_array($this, [self::Paid, self::Failed, self::Cancelled], true);
    }
}
