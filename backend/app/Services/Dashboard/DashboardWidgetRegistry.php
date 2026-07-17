<?php

namespace App\Services\Dashboard;

use App\Contracts\Dashboard\DashboardWidgetInterface;
use App\Contracts\Dashboard\DashboardWidgetRegistryInterface;

class DashboardWidgetRegistry implements DashboardWidgetRegistryInterface
{
    /**
     * @var list<DashboardWidgetInterface>
     */
    private array $widgets = [];

    /**
     * @param  iterable<DashboardWidgetInterface>  $widgets
     */
    public function __construct(iterable $widgets = [])
    {
        foreach ($widgets as $widget) {
            $this->register($widget);
        }
    }

    public function register(DashboardWidgetInterface $widget): void
    {
        $this->widgets[] = $widget;
    }

    /**
     * Every registered widget is enabled in this phase; per-widget enablement
     * arrives in a later phase without changing this contract.
     *
     * @return list<DashboardWidgetInterface>
     */
    public function enabled(): array
    {
        return $this->widgets;
    }
}
