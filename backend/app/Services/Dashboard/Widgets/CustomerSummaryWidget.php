<?php

namespace App\Services\Dashboard\Widgets;

use App\Contracts\Dashboard\DashboardWidgetInterface;

class CustomerSummaryWidget implements DashboardWidgetInterface
{
    public function key(): string
    {
        return 'customers';
    }

    /**
     * Placeholder payload only; analytics queries arrive in a later phase.
     *
     * @return array<string, mixed>
     */
    public function resolve(): array
    {
        return [
            'total' => 0,
        ];
    }
}
