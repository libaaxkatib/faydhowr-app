<?php

namespace App\Repositories\Accounting;

use App\Contracts\Accounting\Repositories\FinancialReportRepositoryInterface;
use App\Enums\Accounting\JournalEntryStatus;
use App\Models\JournalEntryLine;
use App\Support\Accounting\Money;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;

class FinancialReportRepository implements FinancialReportRepositoryInterface
{
    public function categoryTotals(?CarbonInterface $startDate = null, ?CarbonInterface $endDate = null): array
    {
        $aggregates = JournalEntryLine::query()
            ->join('journal_entries', 'journal_entries.id', '=', 'journal_entry_lines.journal_entry_id')
            ->join('accounts', 'accounts.id', '=', 'journal_entry_lines.account_id')
            ->where('journal_entries.status', JournalEntryStatus::Posted->value)
            ->when($startDate, fn (Builder $query) => $query->whereDate('journal_entries.entry_date', '>=', $startDate))
            ->when($endDate, fn (Builder $query) => $query->whereDate('journal_entries.entry_date', '<=', $endDate))
            ->groupBy('accounts.account_category')
            ->selectRaw(
                'accounts.account_category AS category, '
                .'COALESCE(SUM(journal_entry_lines.debit), 0) AS total_debit, '
                .'COALESCE(SUM(journal_entry_lines.credit), 0) AS total_credit',
            )
            ->get();

        $totals = [];

        foreach ($aggregates as $row) {
            $totals[$row->category] = [
                'debit_cents' => Money::toCents($row->total_debit),
                'credit_cents' => Money::toCents($row->total_credit),
            ];
        }

        return $totals;
    }
}
