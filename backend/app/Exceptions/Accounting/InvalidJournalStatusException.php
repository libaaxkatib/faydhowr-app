<?php

namespace App\Exceptions\Accounting;

use App\Enums\Accounting\JournalEntryStatus;
use App\Models\JournalEntry;
use DomainException;

class InvalidJournalStatusException extends DomainException
{
    public static function cannotPost(JournalEntry $entry): self
    {
        return new self(sprintf(
            'Journal entry [%s] cannot be posted because its status is [%s]. Only [%s] entries may be posted.',
            $entry->entry_number,
            $entry->status->value,
            JournalEntryStatus::Draft->value,
        ));
    }
}
