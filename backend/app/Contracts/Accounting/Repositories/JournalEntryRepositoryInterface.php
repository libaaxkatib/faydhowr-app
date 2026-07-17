<?php

namespace App\Contracts\Accounting\Repositories;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read access to journal entries. Creation, filtering, and write
 * operations beyond posting are added in later phases.
 */
interface JournalEntryRepositoryInterface
{
    public function findById(int $id): ?JournalEntry;

    public function findByEntryNumber(string $entryNumber): ?JournalEntry;

    /**
     * The most recent journal entries with their lines, ordered by entry
     * date then id, newest first.
     *
     * @return Collection<int, JournalEntry>
     */
    public function latest(int $limit): Collection;

    /**
     * Persist the Draft → Posted status transition. Validation is owned by
     * the posting engine, never by the repository.
     */
    public function markAsPosted(JournalEntry $entry): JournalEntry;
}
