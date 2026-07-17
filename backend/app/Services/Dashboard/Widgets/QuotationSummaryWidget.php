<?php

namespace App\Services\Dashboard\Widgets;

use App\Contracts\Dashboard\DashboardWidgetInterface;

class QuotationSummaryWidget implements DashboardWidgetInterface
{
    public function key(): string
    {
        return 'quotations';
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
