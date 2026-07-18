<?php

namespace App\Contracts\Customer\Services;

use App\Models\Admin;
use App\Models\CustomerAttachment;
use App\Models\CustomerProfile;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface AttachmentServiceInterface
{
    /**
     * @return LengthAwarePaginator<int, CustomerAttachment>
     */
    public function paginate(CustomerProfile $profile, int $perPage = 15): LengthAwarePaginator;

    public function store(CustomerProfile $profile, UploadedFile $file, Admin $admin): CustomerAttachment;

    public function find(CustomerProfile $profile, int $attachmentId): CustomerAttachment;

    public function download(CustomerAttachment $attachment): StreamedResponse;

    public function delete(CustomerProfile $profile, CustomerAttachment $attachment, Admin $admin): void;
}
