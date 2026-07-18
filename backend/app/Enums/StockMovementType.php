<?php

namespace App\Enums;

enum StockMovementType: string
{
    case PurchaseReceipt = 'purchase_receipt';
    case CustomerSale = 'customer_sale';
    case SaleReversal = 'sale_reversal';
    case Adjustment = 'adjustment';
    case Correction = 'correction';
    case Damage = 'damage';
    case Loss = 'loss';
}
