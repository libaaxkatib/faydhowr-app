<?php

namespace App\Services\Accounting\Services;

use App\Contracts\Accounting\Repositories\JournalEntryRepositoryInterface;
use App\Contracts\Accounting\Services\JournalEntryServiceInterface;
use App\Contracts\Accounting\Services\JournalPostingServiceInterface;
use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Journal entry service. Posting is delegated to the posting engine;
 * journal generation is implemented in later phases.
 */
class JournalEntryService implements JournalEntryServiceInterface
{
    public function __construct(
        private JournalEntryRepositoryInterface $journalEntryRepository,
        private JournalPostingServiceInterface $journalPostingService,
    ) {}

    public function post(JournalEntry $entry): JournalEntry
    {
        return $this->journalPostingService->post($entry);
    }

    public function latest(int $limit): Collection
    {
        return $this->journalEntryRepository->latest($limit);
    }
}
