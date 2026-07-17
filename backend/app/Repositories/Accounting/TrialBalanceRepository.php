<?php

namespace App\Repositories\Accounting;

use App\Contracts\Accounting\Repositories\TrialBalanceRepositoryInterface;
use App\DataTransferObjects\Accounting\TrialBalanceRowData;
use App\Enums\Accounting\JournalEntryStatus;
use App\Enums\Accounting\NormalBalance;
use App\Models\JournalEntryLine;
use App\Support\Accounting\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class TrialBalanceRepository implements TrialBalanceRepositoryInterface
{
    public function rows(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): array
    {
        $aggregates = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->when($startDate, fn (Builder $query) => $query->whereDate('journal_entries.entry_date', '>=', $startDate))
            ->when($endDate, fn (Builder $query) => $query->whereDate('journal_entries.entry_date', '<=', $endDate))
            ->groupBy('accounts.id', 'accounts.code', 'accounts.name', 'accounts.normal_balance')
            ->orderBy('accounts.code')
            ->selectRaw(
                'accounts.id AS account_id, accounts.code AS account_code, accounts.name AS account_name, '
                .'accounts.normal_balance AS normal_balance, '
                .'COALESCE(SUM(journal_entry_lines.debit), 0) AS total_debit, '
                .'COALESCE(SUM(journal_entry_lines.credit), 0) AS total_credit',
            )
            ->get();

        return $aggregates
            ->map(function (object $row): TrialBalanceRowData {
                $normalBalance = NormalBalance::from($row->normal_balance);
                $totalDebitCents = Money::toCents($row->total_debit);
                $totalCreditCents = Money::toCents($row->total_credit);

                $balanceCents = $normalBalance === NormalBalance::Debit
                    ? $totalDebitCents - $totalCreditCents
                    : $totalCreditCents - $totalDebitCents;

                return new TrialBalanceRowData(
                    accountId: (int) $row->account_id,
                    accountCode: $row->account_code,
                    accountName: $row->account_name,
                    normalBalance: $normalBalance,
                    totalDebit: Money::fromCents($totalDebitCents),
                    totalCredit: Money::fromCents($totalCreditCents),
                    currentBalance: Money::fromCents($balanceCents),
                );
            })
            ->values()
            ->all();
    }
}
