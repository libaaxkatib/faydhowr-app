<?php

namespace App\Repositories\Customer;

use App\Contracts\Customer\Repositories\CustomerAttachmentRepositoryInterface;
use App\Models\CustomerAttachment;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerAttachmentRepository implements CustomerAttachmentRepositoryInterface
{
    public function paginateForProfile(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator
    {
        return $profile->attachments()
            ->with('admin')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findForProfile(CustomerProfile $profile, int $attachmentId): ?CustomerAttachment
    {
        return $profile->attachments()->with('admin')->whereKey($attachmentId)->first();
    }

    public function create(CustomerProfile $profile, array $attributes): CustomerAttachment
    {
        /** @var CustomerAttachment $attachment */
        $attachment = $profile->attachments()->create($attributes);

        return $attachment->load('admin');
    }

    public function delete(CustomerAttachment $attachment): void
    {
        $attachment->delete();
    }
}
