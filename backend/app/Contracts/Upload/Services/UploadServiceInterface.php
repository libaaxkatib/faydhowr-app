<?php

namespace App\Contracts\Upload\Services;

use App\Models\CustomerProfile;
use App\Models\Upload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Symfony\Component\HttpFoundation\StreamedResponse;

interface UploadServiceInterface
{
    /**
     * Stage the given files for the owner.
     *
     * @param  list<UploadedFile>  $files
     * @return Collection<int, Upload>
     */
    public function store(CustomerProfile $profile, array $files): Collection;

    /**
     * @return LengthAwarePaginator<int, Upload>
     */
    public function listStaged(CustomerProfile $profile, int $perPage): LengthAwarePaginator;

    public function findForOwner(CustomerProfile $profile, string $uuid): Upload;

    public function stream(Upload $upload): StreamedResponse;

    public function delete(CustomerProfile $profile, string $uuid): void;

    /**
     * Remove expired unattached uploads (file content and records).
     *
     * @return int Number of uploads removed
     */
    public function purgeExpired(): int;
}
