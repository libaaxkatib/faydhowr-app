<?php

namespace App\Enums;

enum ReportType: string
{
    case Bookings = 'bookings';
    case Quotations = 'quotations';
    case Orders = 'orders';
    case Payments = 'payments';
    case StoreOrders = 'store_orders';
    case Inventory = 'inventory';
    case Suppliers = 'suppliers';
    case PurchaseOrders = 'purchase_orders';
    case GoodsReceipts = 'goods_receipts';
    case Customers = 'customers';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
