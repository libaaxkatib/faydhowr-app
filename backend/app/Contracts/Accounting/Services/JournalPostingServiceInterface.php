<?php

namespace App\Contracts\Accounting\Services;

use App\Exceptions\Accounting\InsufficientJournalLinesException;
use App\Exceptions\Accounting\InvalidJournalStatusException;
use App\Exceptions\Accounting\JournalNotBalancedException;
use App\Exceptions\Accounting\PostingToGroupAccountException;
use App\Models\JournalEntry;

/**
 * Posting engine for journal entries. Posted entries are immutable: any
 * future correction must be made through a reversing journal entry, never
 * by editing or re-opening the posted entry.
 */
interface JournalPostingServiceInterface
{
    /**
     * Validate the entry and mark it as posted inside one database
     * transaction. Ledger updates are added in a later phase.
     *
     * @throws InvalidJournalStatusException if the entry is not a draft
     * @throws InsufficientJournalLinesException if the entry has fewer than two lines
     * @throws PostingToGroupAccountException if a line targets a group account
     * @throws JournalNotBalancedException if debits and credits are not positive and equal
     */
    public function post(JournalEntry $entry): JournalEntry;
}
