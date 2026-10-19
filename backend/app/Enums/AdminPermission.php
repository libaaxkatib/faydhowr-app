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

    case PaymentsView = 'payments.view';
    case PaymentsConfirm = 'payments.confirm';

    case BookingsView = 'bookings.view';
    case BookingsManage = 'bookings.manage';

    case StoreOrdersView = 'store_orders.view';
    case StoreOrdersManage = 'store_orders.manage';

    case QuotationsView = 'quotations.view';
    case QuotationsReview = 'quotations.review';
    case QuotationsIssue = 'quotations.issue';
    case QuotationsManage = 'quotations.manage';

    case ContentView = 'content.view';
    case ContentManage = 'content.manage';

    case HrView = 'hr.view';
    case HrManage = 'hr.manage';
    case HrReportsView = 'hr.reports.view';
    case HrPayrollView = 'hr.payroll.view';
    case HrPayrollManage = 'hr.payroll.manage';
    case HrPayrollApprove = 'hr.payroll.approve';
    case HrImport = 'hr.import';

    case MarketingView = 'marketing.view';
    case MarketingManage = 'marketing.manage';
    case MarketingAssign = 'marketing.assign';
    case MarketingCommissionView = 'marketing.commission.view';
    case MarketingReportsView = 'marketing.reports.view';

    case ReconciliationView = 'reconciliation.view';
    case ReconciliationCreate = 'reconciliation.create';
    case ReconciliationUpdate = 'reconciliation.update';
    case ReconciliationResolve = 'reconciliation.resolve';

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
            self::PaymentsView => 'View Payments',
            self::PaymentsConfirm => 'Confirm Payments',
            self::BookingsView => 'View Bookings',
            self::BookingsManage => 'Manage Bookings',
            self::StoreOrdersView => 'View Store Orders',
            self::StoreOrdersManage => 'Manage Store Orders',
            self::QuotationsView => 'View Quotations',
            self::QuotationsReview => 'Review Quotations',
            self::QuotationsIssue => 'Issue Quotations',
            self::QuotationsManage => 'Manage Quotations',
            self::ContentView => 'View Home Content',
            self::ContentManage => 'Manage Home Content',
            self::HrView => 'View HR',
            self::HrManage => 'Manage HR',
            self::HrReportsView => 'View HR Reports',
            self::HrPayrollView => 'View Payroll',
            self::HrPayrollManage => 'Prepare and Review Payroll',
            self::HrPayrollApprove => 'Approve, Finalize and Correct Payroll',
            self::HrImport => 'Import HR Data (Super Admin only)',
            self::MarketingView => 'View Marketing',
            self::MarketingManage => 'Manage Marketing',
            self::MarketingAssign => 'Assign Marketing Work',
            self::MarketingCommissionView => 'View Marketing Commission',
            self::MarketingReportsView => 'View Marketing Reports',
            self::ReconciliationView => 'View Data Issues',
            self::ReconciliationCreate => 'Create Data Issues',
            self::ReconciliationUpdate => 'Update Data Issues',
            self::ReconciliationResolve => 'Resolve Data Issues',
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
            self::PaymentsView,
            self::PaymentsConfirm => 'Payments',
            self::BookingsView,
            self::BookingsManage => 'Bookings',
            self::StoreOrdersView,
            self::StoreOrdersManage => 'Store Orders',
            self::QuotationsView,
            self::QuotationsReview,
            self::QuotationsIssue,
            self::QuotationsManage => 'Quotations',
            self::ContentView,
            self::ContentManage => 'Content',
            self::HrView,
            self::HrManage,
            self::HrReportsView,
            self::HrPayrollView,
            self::HrPayrollManage,
            self::HrPayrollApprove,
            self::HrImport => 'HR',
            self::MarketingView,
            self::MarketingManage,
            self::MarketingAssign,
            self::MarketingCommissionView,
            self::MarketingReportsView => 'Marketing',
            self::ReconciliationView,
            self::ReconciliationCreate,
            self::ReconciliationUpdate,
            self::ReconciliationResolve => 'Data Issues & Reconciliation',
        };
    }

    /**
     * Permissions reserved for the Super Admin role. They are implicit for Super Admin and can never be
     * granted to another role or admin, whether through the Roles & Permissions screen, the direct
     * admin-permission endpoint, or a stray database row (the resolver ignores such grants). None is
     * reserved at present; hr.import is limited to the HR Manager role instead (see restrictedToRoles()).
     */
    public function isSuperAdminOnly(): bool
    {
        return false;
    }

    /**
     * Roles that may hold this permission besides the (implicit) Super Admin, or null when any role may.
     * Owner decision 2026-10-19: the HR Manager gets a dedicated import permission, so hr.import can be
     * granted to the HR Manager role and to no other role. A grant to any other role is refused by every
     * grant endpoint and ignored by the resolver, even if a row is written straight into the database.
     *
     * @return list<AdminRole>|null
     */
    public function restrictedToRoles(): ?array
    {
        return match ($this) {
            self::HrImport => [AdminRole::HrManager],
            default => null,
        };
    }

    public function grantableTo(AdminRole $role): bool
    {
        if ($this->isSuperAdminOnly()) {
            return false;
        }

        $roles = $this->restrictedToRoles();

        return $roles === null || in_array($role, $roles, true);
    }

    /**
     * @param  list<string>  $keys
     * @return list<string> the keys that may not be held by $role
     */
    public static function notGrantableTo(AdminRole $role, array $keys): array
    {
        return array_values(array_filter(
            $keys,
            fn (string $key): bool => (self::tryFrom($key)?->grantableTo($role)) === false,
        ));
    }

    /**
     * @return list<string>
     */
    public static function superAdminOnlyValues(): array
    {
        return array_values(array_map(
            fn (self $permission): string => $permission->value,
            array_filter(self::cases(), fn (self $permission): bool => $permission->isSuperAdminOnly()),
        ));
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
