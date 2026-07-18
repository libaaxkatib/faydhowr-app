<?php

namespace App\Console\Commands;

use App\Contracts\Upload\Services\UploadServiceInterface;
use Illuminate\Console\Command;

class PurgeExpiredUploads extends Command
{
    protected $signature = 'uploads:purge-expired';

    protected $description = 'Remove expired unattached staged uploads (file content and records)';

    public function handle(UploadServiceInterface $uploads): int
    {
        $removed = $uploads->purgeExpired();

        $this->info("Removed {$removed} expired upload(s).");

        return self::SUCCESS;
    }
}
