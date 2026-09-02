<?php

namespace App\Actions\Hr;

use App\Models\WorkLocation;
use Illuminate\Database\Eloquent\Collection;

class ListWorkLocationsAction
{
    public function handle(?int $clientCompanyId = null): Collection
    {
        $query = WorkLocation::query()->with('clientCompany')->withCount([
            'workAssignments as active_assignments_count' => fn ($q) => $q->where('status', 'active'),
        ]);

        if ($clientCompanyId !== null) {
            $query->where('client_company_id', $clientCompanyId);
        }

        return $query->orderBy('name')->get();
    }
}
