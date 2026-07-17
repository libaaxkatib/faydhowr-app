<?php

namespace App\Contracts\Accounting;

use App\Contracts\Accounting\Services\AccountingPeriodServiceInterface;
use App\Contracts\Accounting\Services\ChartOfAccountServiceInterface;
use App\Contracts\Accounting\Services\FinancialReportServiceInterface;
use App\Contracts\Accounting\Services\JournalEntryServiceInterface;
use App\Contracts\Accounting\Services\LedgerServiceInterface;
use App\Contracts\Accounting\Services\TrialBalanceServiceInterface;

/**
 * Single entry point of the Accounting module. The module follows a General
 * Ledger architecture: all future financial transactions flow through
 * journal entries into the ledger, and financial reports are derived from
 * the ledger. Controllers and actions must never reach accounting services
 * or repositories directly.
 */
interface AccountingManagerInterface
{
    public function chartOfAccounts(): ChartOfAccountServiceInterface;

    public function journalEntries(): JournalEntryServiceInterface;

    public function ledger(): LedgerServiceInterface;

    public function financialReports(): FinancialReportServiceInterface;

    public function accountingPeriods(): AccountingPeriodServiceInterface;

    public function trialBalance(): TrialBalanceServiceInterface;
}
