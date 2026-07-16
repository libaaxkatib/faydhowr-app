<?php

namespace App\Enums;

enum NotificationType: string
{
    case Booking = 'booking';
    case Quotation = 'quotation';
    case Order = 'order';
    case Payment = 'payment';
    case StoreOrder = 'store_order';
    case Inventory = 'inventory';
    case System = 'system';
}
