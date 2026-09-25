<?php

namespace App\Actions\Hr;

use App\Models\TemporaryReplacement;
use Illuminate\Database\Eloquent\Collection;

class ListTemporaryReplacementsAction
{
    public function handle(): Collection
    {
        return TemporaryReplacement::query()
            ->with(['workAssignment.employee', 'workAssignment.workLocation.clientCompany', 'replacementEmployee', 'createdBy', 'payments.paidBy'])
            ->orderByDesc('start_date')
            ->get();
    }
}
