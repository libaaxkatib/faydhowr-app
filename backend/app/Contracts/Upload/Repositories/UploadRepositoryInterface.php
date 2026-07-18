<?php

namespace App\Contracts\Upload\Repositories;

use App\DataTransferObjects\Upload\CreateUploadData;
use App\Models\Upload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;

interface UploadRepositoryInterface
{
    public function create(CreateUploadData $data): Upload;

    public function findByUuidForOwner(string $uuid, int $customerProfileId): ?Upload;

    /**
     * Unattached, non-expired uploads for the owner (API Design §14.1 list).
     *
     * @return LengthAwarePaginator<int, Upload>
     */
    public function paginateStagedForOwner(int $customerProfileId, int $perPage): LengthAwarePaginator;

    /**
     * Total bytes of unattached, non-expired staged uploads for the owner.
     */
    public function stagedBytesForOwner(int $customerProfileId): int;

    public function delete(Upload $upload): void;

    /**
     * Unattached uploads whose staging expiry has passed (§14.8 cleanup).
     *
     * @return Collection<int, Upload>
     */
    public function expiredStaged(): Collection;
}
