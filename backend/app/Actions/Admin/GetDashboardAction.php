<?php

namespace App\Actions\Admin;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Support\AdminPermissionResolver;
use Illuminate\Http\Request;

class GetDashboardAction
{
    public function __construct(
        private AdminPermissionResolver $permissionResolver,
        private GetDashboardStatisticsAction $getDashboardStatistics,
    ) {}

    /**
     * @return array{
     *     dashboard_type: string,
     *     role: string,
     *     visible_modules: list<string>,
     *     visible_navigation: list<array{key: string, label: string}>,
     *     statistics: array<string, int>
     * }
     */
    public function handle(Admin $admin, Request $request): array
    {
        $dashboard = $admin->role === AdminRole::SuperAdmin
            ? $this->superAdminDashboard($admin)
            : $this->operationsDashboard($admin, $request);

        $dashboard['statistics'] = $this->getDashboardStatistics->handle(
            $dashboard['dashboard_type'],
            $dashboard['visible_modules'],
        );

        return $dashboard;
    }

    /**
     * Ordered dashboard modules for Dual Dashboard Architecture.
     *
     * @return list<array{key: string, label: string, permissions: list<string>|null}>
     */
    private function modules(): array
    {
        return [
            [
                'key' => 'dashboard',
                'label' => 'Dashboard',
                'permissions' => null,
            ],
            [
                'key' => 'admin_management',
                'label' => 'Admin Management',
                'permissions' => [
                    AdminPermission::AdminsManage->value,
                ],
            ],
            [
                'key' => 'roles_permissions',
                'label' => 'Roles & Permissions',
                'permissions' => [
                    AdminPermission::RolesManage->value,
                ],
            ],
            [
                'key' => 'customers',
                'label' => 'Customers',
                'permissions' => [],
            ],
            [
                'key' => 'services',
                'label' => 'Services',
                'permissions' => [],
            ],
            [
                'key' => 'bookings',
                'label' => 'Bookings',
                'permissions' => [],
            ],
            [
                'key' => 'quotations',
                'label' => 'Quotations',
                'permissions' => [],
            ],
            [
                'key' => 'orders',
                'label' => 'Orders',
                'permissions' => [],
            ],
            [
                'key' => 'store',
                'label' => 'Store',
                'permissions' => [
                    AdminPermission::ProductsCreate->value,
                    AdminPermission::ProductsUpdate->value,
                    AdminPermission::ProductsDelete->value,
                ],
            ],
            [
                'key' => 'inventory',
                'label' => 'Inventory',
                'permissions' => [
                    AdminPermission::SuppliersManage->value,
                    AdminPermission::PurchaseOrdersManage->value,
                    AdminPermission::GoodsReceiptsManage->value,
                ],
            ],
            [
                'key' => 'payments',
                'label' => 'Payments',
                'permissions' => [],
            ],
            [
                'key' => 'reports',
                'label' => 'Reports',
                'permissions' => [],
            ],
            [
                'key' => 'system_settings',
                'label' => 'System Settings',
                'permissions' => [],
            ],
        ];
    }

    /**
     * @return array{
     *     dashboard_type: string,
     *     role: string,
     *     visible_modules: list<string>,
     *     visible_navigation: list<array{key: string, label: string}>
     * }
     */
    private function superAdminDashboard(Admin $admin): array
    {
        $navigation = array_map(
            fn (array $module): array => [
                'key' => $module['key'],
                'label' => $module['label'],
            ],
            $this->modules(),
        );

        return [
            'dashboard_type' => 'super_admin',
            'role' => $admin->role->value,
            'visible_modules' => array_column($navigation, 'key'),
            'visible_navigation' => $navigation,
        ];
    }

    /**
     * @return array{
     *     dashboard_type: string,
     *     role: string,
     *     visible_modules: list<string>,
     *     visible_navigation: list<array{key: string, label: string}>
     * }
     */
    private function operationsDashboard(Admin $admin, Request $request): array
    {
        $effectivePermissions = $this->permissionResolver->keysFor($admin, $request);

        $navigation = [];

        foreach ($this->modules() as $module) {
            if (! $this->isModuleVisible($module['permissions'], $effectivePermissions)) {
                continue;
            }

            $navigation[] = [
                'key' => $module['key'],
                'label' => $module['label'],
            ];
        }

        return [
            'dashboard_type' => 'operations',
            'role' => $admin->role->value,
            'visible_modules' => array_column($navigation, 'key'),
            'visible_navigation' => $navigation,
        ];
    }

    /**
     * @param  list<string>|null  $requiredPermissions
     * @param  list<string>  $effectivePermissions
     */
    private function isModuleVisible(?array $requiredPermissions, array $effectivePermissions): bool
    {
        if ($requiredPermissions === null) {
            return true;
        }

        if ($requiredPermissions === []) {
            return false;
        }

        foreach ($requiredPermissions as $permission) {
            if (in_array($permission, $effectivePermissions, true)) {
                return true;
            }
        }

        return false;
    }
}
