<?php

namespace App\Exceptions\Accounting;

use App\Models\JournalEntry;
use DomainException;

class JournalNotBalancedException extends DomainException
{
    public static function totalsDiffer(JournalEntry $entry, string $totalDebit, string $totalCredit): self
    {
        return new self(sprintf(
            'Journal entry [%s] is not balanced: total debit [%s] does not equal total credit [%s].',
            $entry->entry_number,
            $totalDebit,
            $totalCredit,
        ));
    }

    public static function totalsNotPositive(JournalEntry $entry): self
    {
        return new self(sprintf(
            'Journal entry [%s] cannot be posted: total debit and total credit must both be greater than zero.',
            $entry->entry_number,
        ));
    }
}
