<?php

namespace App\Contracts\Customer\Services;

use App\DataTransferObjects\Customer\CreateNoteData;
use App\Models\Admin;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface NoteServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, CustomerNote>
     */
    public function paginate(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator;

    public function create(CustomerProfile $profile, CreateNoteData $data, Admin $admin): CustomerNote;
}
