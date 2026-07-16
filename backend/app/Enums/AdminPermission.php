<?php

namespace App\Enums;

enum AdminPermission: string
{
    case ProductsCreate = 'products.create';
    case ProductsUpdate = 'products.update';
    case ProductsDelete = 'products.delete';

    case SuppliersManage = 'suppliers.manage';
    case PurchaseOrdersManage = 'purchase_orders.manage';
    case GoodsReceiptsManage = 'goods_receipts.manage';

    case AdminsManage = 'admins.manage';
    case RolesManage = 'roles.manage';
    case NotificationsManage = 'notifications.manage';

    public function label(): string
    {
        return match ($this) {
            self::ProductsCreate => 'Create Products',
            self::ProductsUpdate => 'Update Products',
            self::ProductsDelete => 'Delete Products',
            self::SuppliersManage => 'Manage Suppliers',
            self::PurchaseOrdersManage => 'Manage Purchase Orders',
            self::GoodsReceiptsManage => 'Manage Goods Receipts',
            self::AdminsManage => 'Manage Admins',
            self::RolesManage => 'Manage Roles',
            self::NotificationsManage => 'Manage Notifications',
        };
    }

    public function group(): string
    {
        return match ($this) {
            self::ProductsCreate,
            self::ProductsUpdate,
            self::ProductsDelete => 'Products',
            self::SuppliersManage,
            self::PurchaseOrdersManage,
            self::GoodsReceiptsManage => 'Inventory',
            self::AdminsManage,
            self::RolesManage,
            self::NotificationsManage => 'Admins',
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
