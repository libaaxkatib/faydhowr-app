<?php

namespace App\Actions\Marketing;

use App\Enums\AdminRole;
use App\Enums\AdminStatus;
use App\Models\Admin;
use Illuminate\Database\Eloquent\Collection;

/**
 * Backs docs/HRM_MARKETING_SRS.md §3/§4.2's Marketing Employees screen and
 * the Marketing Employee selects on the XARUN/PROJECT forms and the
 * assignment panel. Reuses the existing Admin entity (scoped to the two
 * marketing roles) rather than a separate employee table — there is no
 * marketing-specific "employee" concept beyond an Admin account.
 */
class ListMarketingEmployeesAction
{
    public function handle(): Collection
    {
        return Admin::query()
            ->whereIn('role', [AdminRole::MarketingManager->value, AdminRole::MarketingEmployee->value])
            ->where('status', AdminStatus::Active->value)
            ->with('marketingTeams')
            ->orderBy('full_name')
            ->get();
    }
}
