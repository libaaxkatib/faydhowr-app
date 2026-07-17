<?php

namespace App\Contracts\Accounting\Services;

use App\Models\JournalEntry;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contract for the journal entry service of the Accounting module. All
 * future financial transactions flow through journal entries; journal
 * generation methods are defined in later phases.
 */
interface JournalEntryServiceInterface
{
    /**
     * Post a draft journal entry through the posting engine.
     *
     * @see JournalPostingServiceInterface::post() for the validation rules
     */
    public function post(JournalEntry $entry): JournalEntry;

    /**
     * The most recent journal entries with their lines.
     *
     * @return Collection<int, JournalEntry>
     */
    public function latest(int $limit): Collection;
}
