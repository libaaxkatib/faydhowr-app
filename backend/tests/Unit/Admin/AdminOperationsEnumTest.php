<?php

namespace Tests\Unit\Admin;

use App\Enums\AdminPermission;
use App\Enums\AuditAction;
use App\Enums\StockMovementType;
use PHPUnit\Framework\TestCase;

class AdminOperationsEnumTest extends TestCase
{
    public function test_the_sprint_27_permission_keys_exist(): void
    {
        $values = AdminPermission::values();

        foreach ([
            'payments.view',
            'payments.confirm',
            'bookings.view',
            'bookings.manage',
            'store_orders.view',
            'store_orders.manage',
        ] as $key) {
            self::assertContains($key, $values);
        }
    }

    public function test_the_sprint_27_permissions_have_labels_and_groups(): void
    {
        self::assertSame('View Payments', AdminPermission::PaymentsView->label());
        self::assertSame('Payments', AdminPermission::PaymentsView->group());
        self::assertSame('Confirm Payments', AdminPermission::PaymentsConfirm->label());
        self::assertSame('Bookings', AdminPermission::BookingsManage->group());
        self::assertSame('Store Orders', AdminPermission::StoreOrdersManage->group());
    }

    public function test_the_sale_reversal_movement_type_exists(): void
    {
        self::assertSame('sale_reversal', StockMovementType::SaleReversal->value);
    }

    public function test_the_admin_operations_audit_actions_exist(): void
    {
        $values = AuditAction::values();

        foreach ([
            'payment_confirm',
            'payment_reject',
            'booking_schedule',
            'booking_start',
            'booking_complete',
            'booking_close',
            'booking_cancel',
            'store_order_status_change',
        ] as $action) {
            self::assertContains($action, $values);
        }
    }
}
