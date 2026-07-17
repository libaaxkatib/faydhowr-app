<?php

namespace App\Contracts\Dashboard;

interface DashboardWidgetInterface
{
    /**
     * The stable public API key this widget's payload is exposed under.
     */
    public function key(): string;

    /**
     * Resolve the widget payload. Widgets are fully independent and must
     * never communicate with other widgets.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array;
}
