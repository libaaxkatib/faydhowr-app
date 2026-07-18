<?php

namespace App\Repositories\Customer;

use App\Contracts\Customer\Repositories\CustomerNoteRepositoryInterface;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class CustomerNoteRepository implements CustomerNoteRepositoryInterface
{
    public function paginateForProfile(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator
    {
        return $profile->notes()
            ->with('admin')
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function create(CustomerProfile $profile, int $adminId, string $body): CustomerNote
    {
        /** @var CustomerNote $note */
        $note = $profile->notes()->create([
            'admin_id' => $adminId,
            'body' => $body,
        ]);

        return $note->load('admin');
    }
}
