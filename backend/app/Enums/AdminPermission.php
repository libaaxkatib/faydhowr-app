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

    case ReportsView = 'reports.view';

    case DashboardView = 'dashboard.view';

    case AccountingView = 'accounting.view';

    case SettingsView = 'settings.view';
    case SettingsManage = 'settings.manage';

    case CustomersView = 'customers.view';
    case CustomersCreate = 'customers.create';
    case CustomersUpdate = 'customers.update';
    case CustomersDelete = 'customers.delete';
    case CustomersRestore = 'customers.restore';
    case CustomersNotes = 'customers.notes';
    case CustomersAttachments = 'customers.attachments';

    case ReviewsView = 'reviews.view';
    case ReviewsModerate = 'reviews.moderate';

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
            self::ReportsView => 'View Reports',
            self::DashboardView => 'View Dashboard',
            self::AccountingView => 'View Accounting',
            self::SettingsView => 'View Settings',
            self::SettingsManage => 'Manage Settings',
            self::CustomersView => 'View Customers',
            self::CustomersCreate => 'Create Customers',
            self::CustomersUpdate => 'Update Customers',
            self::CustomersDelete => 'Delete Customers',
            self::CustomersRestore => 'Restore Customers',
            self::CustomersNotes => 'Customer Notes',
            self::CustomersAttachments => 'Customer Attachments',
            self::ReviewsView => 'View Reviews',
            self::ReviewsModerate => 'Moderate Reviews',
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
            self::ReportsView => 'Reports',
            self::DashboardView => 'Dashboard',
            self::AccountingView => 'Accounting',
            self::SettingsView,
            self::SettingsManage => 'Settings',
            self::CustomersView,
            self::CustomersCreate,
            self::CustomersUpdate,
            self::CustomersDelete,
            self::CustomersRestore,
            self::CustomersNotes,
            self::CustomersAttachments => 'Customers',
            self::ReviewsView,
            self::ReviewsModerate => 'Reviews',
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
