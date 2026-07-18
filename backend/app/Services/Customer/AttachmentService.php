<?php

namespace App\Services\Customer;

use App\Contracts\Customer\Repositories\CustomerAttachmentRepositoryInterface;
use App\Contracts\Customer\Services\AttachmentServiceInterface;
use App\Enums\AuditAction;
use App\Enums\Customer\AttachmentType;
use App\Events\Audit\AuditEvent;
use App\Exceptions\Customer\CustomerNotFoundException;
use App\Models\Admin;
use App\Models\CustomerAttachment;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class AttachmentService implements AttachmentServiceInterface
{
    public function __construct(private CustomerAttachmentRepositoryInterface $attachments) {}

    public function paginate(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator
    {
        return $this->attachments->paginateForProfile($profile, $perPage);
    }

    public function store(CustomerProfile $profile, UploadedFile $file, Admin $admin): CustomerAttachment
    {
        $directory = 'customers/'.$profile->id.'/attachments';
        $path = $file->store($directory, 'local');

        $attachment = $this->attachments->create($profile, [
            'admin_id' => $admin->id,
            'file_name' => $file->getClientOriginalName(),
            'file_type' => AttachmentType::fromMime($file->getMimeType()),
            'file_size' => $file->getSize() ?: 0,
            'file_path' => $path,
        ]);

        event(AuditEvent::record(
            AuditAction::Create,
            $admin,
            'Customer attachment uploaded.',
            CustomerAttachment::class,
            $attachment->id,
            [
                'customer_profile_id' => $profile->id,
                'file_name' => $attachment->file_name,
                'file_type' => $attachment->file_type?->value,
            ],
        ));

        return $attachment;
    }

    public function find(CustomerProfile $profile, int $attachmentId): CustomerAttachment
    {
        $attachment = $this->attachments->findForProfile($profile, $attachmentId);

        if ($attachment === null) {
            throw CustomerNotFoundException::forId($attachmentId);
        }

        return $attachment;
    }

    public function download(CustomerAttachment $attachment): StreamedResponse
    {
        return Storage::disk('local')->download(
            $attachment->file_path,
            $attachment->file_name,
        );
    }

    public function delete(CustomerProfile $profile, CustomerAttachment $attachment, Admin $admin): void
    {
        if ((int) $attachment->customer_profile_id !== (int) $profile->id) {
            abort(404);
        }

        $path = $attachment->file_path;
        $this->attachments->delete($attachment);

        if ($path !== '' && Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        event(AuditEvent::record(
            AuditAction::Delete,
            $admin,
            'Customer attachment deleted.',
            CustomerAttachment::class,
            $attachment->id,
            ['customer_profile_id' => $profile->id, 'file_name' => $attachment->file_name],
        ));
    }
}
