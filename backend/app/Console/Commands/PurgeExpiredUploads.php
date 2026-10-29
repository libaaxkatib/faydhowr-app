<?php

namespace App\Console\Commands;

use App\Contracts\Upload\Repositories\UploadRepositoryInterface;
use App\Contracts\Upload\Services\UploadServiceInterface;
use Illuminate\Console\Command;

class PurgeExpiredUploads extends Command
{
    protected $signature = 'uploads:purge-expired
        {--dry-run : Only count what would be removed (nothing is changed)}';

    protected $description = 'Remove expired unattached staged uploads (file content and records)';

    public function handle(UploadServiceInterface $uploads, UploadRepositoryInterface $repository): int
    {
        if ($this->option('dry-run')) {
            $due = $repository->expiredStaged()->count();

            $this->info("{$due} expired upload(s) are due for removal (nothing was changed).");

            return self::SUCCESS;
        }

        $removed = $uploads->purgeExpired();

        $this->info("Removed {$removed} expired upload(s).");

        return self::SUCCESS;
    }
}
