<?php

namespace App\Enums;

enum StoreOrderStatus: string
{
    case PendingPayment = 'pending_payment';
    case Confirmed = 'confirmed';
    case Processing = 'processing';
    case Preparing = 'preparing';
    case OutForDelivery = 'out_for_delivery';
    case Delivered = 'delivered';
    case PaymentPending = 'payment_pending';
    case Completed = 'completed';
    case Cancelled = 'cancelled';
}
