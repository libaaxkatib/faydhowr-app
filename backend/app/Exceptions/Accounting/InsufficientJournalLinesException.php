<?php

namespace App\Exceptions\Accounting;

use App\Models\JournalEntry;
use DomainException;

class InsufficientJournalLinesException extends DomainException
{
    public static function forEntry(JournalEntry $entry): self
    {
        return new self(sprintf(
            'Journal entry [%s] must have at least two lines to be posted, %d given.',
            $entry->entry_number,
            $entry->lines->count(),
        ));
    }
}
