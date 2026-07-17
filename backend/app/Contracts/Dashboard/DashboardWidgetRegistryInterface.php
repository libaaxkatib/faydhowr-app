<?php

namespace App\Contracts\Dashboard;

/**
 * Single source of truth for dashboard widget registration. Registration
 * order is the execution order. Future phases may add enable/disable,
 * reordering, and tenant-specific widget support behind this contract.
 */
interface DashboardWidgetRegistryInterface
{
    public function register(DashboardWidgetInterface $widget): void;

    /**
     * The widgets that should execute, in execution order.
     *
     * @return list<DashboardWidgetInterface>
     */
    public function enabled(): array;
}
