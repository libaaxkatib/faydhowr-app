<?php

namespace App\Actions\Hr;

use App\Models\WorkforceRequest;
use Illuminate\Database\Eloquent\Collection;

class ListWorkforceRequestsAction
{
    public function handle(): Collection
    {
        return WorkforceRequest::query()
            ->with(['workLocation.clientCompany', 'employeeCategory', 'position', 'createdBy', 'matches.employee'])
            ->orderByDesc('requested_date')
            ->get();
    }
}
