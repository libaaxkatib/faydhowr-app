<?php

namespace App\Contracts\Customer\Repositories;

use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerNoteRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, CustomerNote>
     */
    public function paginateForProfile(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator;

    public function create(CustomerProfile $profile, int $adminId, string $body): CustomerNote;
}
