<?php

namespace App\Exceptions\Upload;

use RuntimeException;

class UploadStorageLimitExceededException extends RuntimeException
{
    public static function withQuota(int $quotaBytes): self
    {
        $quotaMegabytes = (int) round($quotaBytes / (1024 * 1024));

        return new self("Staged upload storage quota of {$quotaMegabytes} MB exceeded.");
    }
}
