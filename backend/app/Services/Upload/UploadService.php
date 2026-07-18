<?php

namespace App\Services\Upload;

use App\Contracts\Upload\Repositories\UploadRepositoryInterface;
use App\Contracts\Upload\Services\UploadServiceInterface;
use App\DataTransferObjects\Upload\CreateUploadData;
use App\Enums\Upload\UploadMediaType;
use App\Exceptions\Upload\InvalidImageFileException;
use App\Exceptions\Upload\InvalidPdfFileException;
use App\Exceptions\Upload\UploadAttachedException;
use App\Exceptions\Upload\UploadNotFoundException;
use App\Exceptions\Upload\UploadStorageLimitExceededException;
use App\Models\CustomerProfile;
use App\Models\Upload;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use RuntimeException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class UploadService implements UploadServiceInterface
{
    public function __construct(private UploadRepositoryInterface $uploads) {}

    public function store(CustomerProfile $profile, array $files): Collection
    {
        foreach ($files as $file) {
            $this->assertValidFileContent($file);
        }

        $this->assertWithinStagedQuota($profile, $files);

        $disk = (string) config('uploads.disk');
        $directory = trim((string) config('uploads.directory'), '/').'/'.$profile->id;
        $expiresAt = now()->addDays((int) config('uploads.retention_days'));

        $storedPaths = [];

        try {
            return DB::transaction(function () use ($profile, $files, $disk, $directory, $expiresAt, &$storedPaths): Collection {
                $uploads = collect();

                foreach ($files as $file) {
                    $mediaType = UploadMediaType::fromExtension((string) $file->getClientOriginalExtension())
                        ?? UploadMediaType::fromMime((string) $file->getClientMimeType());

                    if ($mediaType === null) {
                        throw new RuntimeException('Unsupported file type slipped past validation.');
                    }

                    $path = $file->store($directory, $disk);

                    if ($path === false) {
                        throw new RuntimeException('Failed to store uploaded file.');
                    }

                    $storedPaths[] = $path;

                    $uploads->push($this->uploads->create(new CreateUploadData(
                        customerProfileId: $profile->id,
                        uuid: (string) Str::uuid(),
                        disk: $disk,
                        path: $path,
                        originalName: (string) $file->getClientOriginalName(),
                        mediaType: $mediaType,
                        mimeType: (string) $file->getClientMimeType(),
                        fileSizeBytes: (int) $file->getSize(),
                        expiresAt: $expiresAt,
                    )));
                }

                return $uploads;
            });
        } catch (Throwable $exception) {
            foreach ($storedPaths as $path) {
                Storage::disk($disk)->delete($path);
            }

            throw $exception;
        }
    }

    public function listStaged(CustomerProfile $profile, int $perPage): LengthAwarePaginator
    {
        return $this->uploads->paginateStagedForOwner($profile->id, $perPage);
    }

    public function findForOwner(CustomerProfile $profile, string $uuid): Upload
    {
        $upload = $this->uploads->findByUuidForOwner($uuid, $profile->id);

        if ($upload === null || $upload->isExpired()) {
            throw UploadNotFoundException::forUuid($uuid);
        }

        return $upload;
    }

    public function stream(Upload $upload): StreamedResponse
    {
        return Storage::disk($upload->disk)->download(
            $upload->path,
            $upload->original_name,
            ['Content-Type' => $upload->mime_type],
        );
    }

    public function delete(CustomerProfile $profile, string $uuid): void
    {
        $upload = $this->findForOwner($profile, $uuid);

        if ($upload->isAttached()) {
            throw UploadAttachedException::forUuid($uuid);
        }

        $this->removeUpload($upload);
    }

    public function purgeExpired(): int
    {
        $expired = $this->uploads->expiredStaged();

        foreach ($expired as $upload) {
            $this->removeUpload($upload);
        }

        return $expired->count();
    }

    /**
     * Server-side content validation after MIME/extension validation:
     * images must be decodable, PDFs must carry the %PDF- signature.
     * Videos are stored without content validation (approved scope).
     */
    private function assertValidFileContent(UploadedFile $file): void
    {
        $mediaType = UploadMediaType::fromExtension((string) $file->getClientOriginalExtension())
            ?? UploadMediaType::fromMime((string) $file->getClientMimeType());

        if ($mediaType === UploadMediaType::Image) {
            $realPath = (string) $file->getRealPath();

            if ($realPath === '' || @getimagesize($realPath) === false) {
                throw InvalidImageFileException::forFile((string) $file->getClientOriginalName());
            }
        }

        if ($mediaType === UploadMediaType::Document) {
            $handle = fopen((string) $file->getRealPath(), 'rb');
            $signature = $handle !== false ? (string) fread($handle, 5) : '';

            if ($handle !== false) {
                fclose($handle);
            }

            if ($signature !== '%PDF-') {
                throw InvalidPdfFileException::forFile((string) $file->getClientOriginalName());
            }
        }
    }

    /**
     * @param  list<UploadedFile>  $files
     */
    private function assertWithinStagedQuota(CustomerProfile $profile, array $files): void
    {
        $quotaBytes = (int) config('uploads.staged_quota_bytes');

        $incomingBytes = array_sum(array_map(
            fn (UploadedFile $file): int => (int) $file->getSize(),
            $files,
        ));

        if ($this->uploads->stagedBytesForOwner($profile->id) + $incomingBytes > $quotaBytes) {
            throw UploadStorageLimitExceededException::withQuota($quotaBytes);
        }
    }

    private function removeUpload(Upload $upload): void
    {
        $disk = $upload->disk;
        $path = $upload->path;

        $this->uploads->delete($upload);

        if ($path !== '' && Storage::disk($disk)->exists($path)) {
            Storage::disk($disk)->delete($path);
        }
    }
}
