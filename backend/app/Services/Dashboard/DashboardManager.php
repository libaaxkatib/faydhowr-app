<?php

namespace App\Services\Dashboard;

use App\Contracts\Dashboard\DashboardWidgetInterface;

class DashboardManager
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
     * @return list<DashboardWidgetInterface>
     */
    public function widgets(): array
    {
        return $this->widgets;
    }

    /**
     * Execute every registered widget and aggregate the payloads keyed by
     * widget key, preserving registration order.
     *
     * @return array<string, array<string, mixed>>
     */
    public function aggregate(): array
    {
        $dashboard = [];

        foreach ($this->widgets as $widget) {
            $dashboard[$widget->key()] = $widget->resolve();
        }

        return $dashboard;
    }
}
