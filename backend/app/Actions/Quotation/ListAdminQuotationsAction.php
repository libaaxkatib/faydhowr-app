<?php

namespace App\Actions\Quotation;

use App\DataTransferObjects\Quotation\AdminQuotationFiltersData;
use App\Models\Quotation;
use App\Support\Search\CatalogSearch;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class ListAdminQuotationsAction
{
    /**
     * @return LengthAwarePaginator<int, Quotation>
     */
    public function handle(AdminQuotationFiltersData $filters): LengthAwarePaginator
    {
        return Quotation::query()
            ->with(['customerProfile', 'booking', 'assignedAdmin', 'latestRevision'])
            ->when($filters->status, fn ($query) => $query->where('status', $filters->status->value))
            ->when($filters->assignedAdminId, fn ($query) => $query->where('assigned_admin_id', $filters->assignedAdminId))
            ->when($filters->customerProfileId, fn ($query) => $query->where('customer_profile_id', $filters->customerProfileId))
            ->when($filters->from, fn ($query) => $query->whereDate('created_at', '>=', $filters->from))
            ->when($filters->to, fn ($query) => $query->whereDate('created_at', '<=', $filters->to))
            ->when($filters->search, fn ($query) => $query->where('quotation_number', 'like', '%'.CatalogSearch::escapeLike((string) $filters->search).'%'))
            ->latest('id')
            ->paginate($filters->perPage);
    }
}
