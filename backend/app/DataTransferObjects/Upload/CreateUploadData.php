<?php

namespace App\DataTransferObjects\Upload;

use App\Enums\Upload\UploadMediaType;
use DateTimeInterface;

final readonly class CreateUploadData
{
    public function __construct(
        public int $customerProfileId,
        public string $uuid,
        public string $disk,
        public string $path,
        public string $originalName,
        public UploadMediaType $mediaType,
        public string $mimeType,
        public int $fileSizeBytes,
        public DateTimeInterface $expiresAt,
    ) {}
}
