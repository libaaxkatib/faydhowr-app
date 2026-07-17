<?php

namespace App\Services\Accounting\Services;

use App\Contracts\Accounting\Repositories\JournalEntryRepositoryInterface;
use App\Contracts\Accounting\Services\JournalPostingServiceInterface;
use App\Enums\Accounting\JournalEntryStatus;
use App\Exceptions\Accounting\InsufficientJournalLinesException;
use App\Exceptions\Accounting\InvalidJournalStatusException;
use App\Exceptions\Accounting\JournalNotBalancedException;
use App\Exceptions\Accounting\PostingToGroupAccountException;
use App\Models\Account;
use App\Models\JournalEntry;
use App\Support\Accounting\Money;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

class JournalPostingService implements JournalPostingServiceInterface
{
    public function __construct(
        private JournalEntryRepositoryInterface $journalEntryRepository,
    ) {}

    public function post(JournalEntry $entry): JournalEntry
    {
        return DB::transaction(function () use ($entry): JournalEntry {
            $this->validate($entry);

            return $this->journalEntryRepository->markAsPosted($entry);
        });
    }

    private function validate(JournalEntry $entry): void
    {
        if ($entry->status !== JournalEntryStatus::Draft) {
            throw InvalidJournalStatusException::cannotPost($entry);
        }

        $entry->load('lines.account');

        if ($entry->lines->count() < 2) {
            throw InsufficientJournalLinesException::forEntry($entry);
        }

        $totalDebitCents = 0;
        $totalCreditCents = 0;

        foreach ($entry->lines as $line) {
            $account = $line->account;

            if ($account === null) {
                throw (new ModelNotFoundException)->setModel(Account::class, [$line->account_id]);
            }

            if ($account->is_group) {
                throw PostingToGroupAccountException::forAccount($entry, $account);
            }

            $totalDebitCents += Money::toCents($line->debit);
            $totalCreditCents += Money::toCents($line->credit);
        }

        if ($totalDebitCents <= 0 || $totalCreditCents <= 0) {
            throw JournalNotBalancedException::totalsNotPositive($entry);
        }

        if ($totalDebitCents !== $totalCreditCents) {
            throw JournalNotBalancedException::totalsDiffer(
                $entry,
                Money::fromCents($totalDebitCents),
                Money::fromCents($totalCreditCents),
            );
        }
    }
}
