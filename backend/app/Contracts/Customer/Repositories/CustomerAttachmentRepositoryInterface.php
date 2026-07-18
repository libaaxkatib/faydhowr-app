<?php

namespace App\Contracts\Customer\Repositories;

use App\Models\CustomerAttachment;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface CustomerAttachmentRepositoryInterface
{
    /**
     * @return LengthAwarePaginator<int, CustomerAttachment>
     */
    public function paginateForProfile(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator;

    public function findForProfile(CustomerProfile $profile, int $attachmentId): ?CustomerAttachment;

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function create(CustomerProfile $profile, array $attributes): CustomerAttachment;

    public function delete(CustomerAttachment $attachment): void;
}
