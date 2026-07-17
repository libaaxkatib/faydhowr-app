<?php

namespace App\Exceptions\Accounting;

use App\Models\Account;
use App\Models\JournalEntry;
use DomainException;

class PostingToGroupAccountException extends DomainException
{
    public static function forAccount(JournalEntry $entry, Account $account): self
    {
        return new self(sprintf(
            'Journal entry [%s] cannot be posted: account [%s %s] is a group account and cannot receive postings.',
            $entry->entry_number,
            $account->code,
            $account->name,
        ));
    }
}
