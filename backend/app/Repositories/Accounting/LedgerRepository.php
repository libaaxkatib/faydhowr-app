<?php

namespace App\Repositories\Accounting;

use App\Contracts\Accounting\Repositories\LedgerRepositoryInterface;
use App\DataTransferObjects\Accounting\LedgerBalanceData;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use App\Models\JournalEntryLine;
use App\Support\Accounting\Money;
use Illuminate\Database\Eloquent\Builder;

class LedgerRepository implements LedgerRepositoryInterface
{
    public function balanceForAccount(Account $account): LedgerBalanceData
    {
        $totals = JournalEntryLine::query()
            ->where('account_id', $account->id)
            ->whereHas(
                'journalEntry',
                fn (Builder $query) => $query->where('status', JournalEntryStatus::Posted),
            )
            ->selectRaw('COALESCE(SUM(debit), 0) AS total_debit, COALESCE(SUM(credit), 0) AS total_credit')
            ->first();

        $totalDebitCents = Money::toCents($totals->total_debit);
        $totalCreditCents = Money::toCents($totals->total_credit);

        $balanceCents = $account->normal_balance === NormalBalance::Debit
            ? $totalDebitCents - $totalCreditCents
            : $totalCreditCents - $totalDebitCents;

        return new LedgerBalanceData(
            accountId: $account->id,
            accountCode: $account->code,
            accountName: $account->name,
            totalDebit: Money::fromCents($totalDebitCents),
            totalCredit: Money::fromCents($totalCreditCents),
            currentBalance: Money::fromCents($balanceCents),
        );
    }
}
