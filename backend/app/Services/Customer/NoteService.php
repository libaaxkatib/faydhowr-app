<?php

namespace App\Services\Customer;

use App\Contracts\Customer\Repositories\CustomerNoteRepositoryInterface;
use App\Contracts\Customer\Services\NoteServiceInterface;
use App\DataTransferObjects\Customer\CreateNoteData;
use App\Enums\AuditAction;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\CustomerNote;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

class NoteService implements NoteServiceInterface
{
    public function __construct(private CustomerNoteRepositoryInterface $notes) {}

    public function paginate(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator
    {
        return $this->notes->paginateForProfile($profile, $perPage);
    }

    public function create(CustomerProfile $profile, CreateNoteData $data, Admin $admin): CustomerNote
    {
        $note = $this->notes->create($profile, $admin->id, $data->body);

        event(AuditEvent::record(
            AuditAction::Create,
            $admin,
            'Customer note created.',
            CustomerNote::class,
            $note->id,
            ['customer_profile_id' => $profile->id],
        ));

        return $note;
    }
}
