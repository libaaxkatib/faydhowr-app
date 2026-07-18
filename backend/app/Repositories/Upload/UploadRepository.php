<?php

namespace App\Repositories\Upload;

use App\Contracts\Upload\Repositories\UploadRepositoryInterface;
use App\DataTransferObjects\Upload\CreateUploadData;
use App\Models\Upload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

class UploadRepository implements UploadRepositoryInterface
{
    public function create(CreateUploadData $data): Upload
    {
        return Upload::query()->create([
            'uuid' => $data->uuid,
            'customer_profile_id' => $data->customerProfileId,
            'disk' => $data->disk,
            'path' => $data->path,
            'original_name' => $data->originalName,
            'media_type' => $data->mediaType,
            'mime_type' => $data->mimeType,
            'file_size_bytes' => $data->fileSizeBytes,
            'attached_at' => null,
            'expires_at' => $data->expiresAt,
        ]);
    }

    public function findByUuidForOwner(string $uuid, int $customerProfileId): ?Upload
    {
        return Upload::query()
            ->where('uuid', $uuid)
            ->where('customer_profile_id', $customerProfileId)
            ->first();
    }

    public function paginateStagedForOwner(int $customerProfileId, int $perPage): LengthAwarePaginator
    {
        return $this->stagedQuery($customerProfileId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function stagedBytesForOwner(int $customerProfileId): int
    {
        return (int) $this->stagedQuery($customerProfileId)->sum('file_size_bytes');
    }

    public function delete(Upload $upload): void
    {
        $upload->delete();
    }

    public function expiredStaged(): Collection
    {
        return Upload::query()
            ->whereNull('attached_at')
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now())
            ->get();
    }

    /**
     * @return Builder<Upload>
     */
    private function stagedQuery(int $customerProfileId): Builder
    {
        return Upload::query()
            ->where('customer_profile_id', $customerProfileId)
            ->whereNull('attached_at')
            ->where('expires_at', '>', now());
    }
}
