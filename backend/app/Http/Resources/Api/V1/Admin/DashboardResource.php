<?php

namespace App\Http\Resources\Api\V1\Admin;

use App\DataTransferObjects\Dashboard\DashboardKpiData;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DashboardResource extends JsonResource
{
    /**
     * The widgets keys (bookings, quotations, orders, payments, revenue,
     * inventory, customers) are part of the public API contract and must
     * remain stable. Widget KPI DTOs are serialized to arrays here, at the
     * API boundary.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'dashboard_type' => $this['dashboard_type'],
            'role' => $this['role'],
            'visible_modules' => $this['visible_modules'],
            'visible_navigation' => $this['visible_navigation'],
            'statistics' => $this['statistics'],
            'widgets' => array_map(
                static fn (DashboardKpiData $kpi): array => $kpi->toArray(),
                $this['widgets'],
            ),
        ];
    }
}
