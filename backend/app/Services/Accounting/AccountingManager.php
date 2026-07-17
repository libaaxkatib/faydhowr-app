<?php

namespace App\Services\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Services\AccountingPeriodServiceInterface;
use App\Contracts\Accounting\Services\ChartOfAccountServiceInterface;
use App\Contracts\Accounting\Services\FinancialReportServiceInterface;
use App\Contracts\Accounting\Services\JournalEntryServiceInterface;
use App\Contracts\Accounting\Services\LedgerServiceInterface;
use App\Contracts\Accounting\Services\TrialBalanceServiceInterface;

/**
 * Single entry point of the Accounting module, exposing the chart of
 * accounts, journal entry, ledger, and financial report services. Owns no
 * business logic itself.
 */
class AccountingManager implements AccountingManagerInterface
{
    public function __construct(
        private ChartOfAccountServiceInterface $chartOfAccountService,
        private JournalEntryServiceInterface $journalEntryService,
        private LedgerServiceInterface $ledgerService,
        private FinancialReportServiceInterface $financialReportService,
        private AccountingPeriodServiceInterface $accountingPeriodService,
        private TrialBalanceServiceInterface $trialBalanceService,
    ) {}

    public function chartOfAccounts(): ChartOfAccountServiceInterface
    {
        return $this->chartOfAccountService;
    }

    public function journalEntries(): JournalEntryServiceInterface
    {
        return $this->journalEntryService;
    }

    public function ledger(): LedgerServiceInterface
    {
        return $this->ledgerService;
    }

    public function financialReports(): FinancialReportServiceInterface
    {
        return $this->financialReportService;
    }

    public function accountingPeriods(): AccountingPeriodServiceInterface
    {
        return $this->accountingPeriodService;
    }

    public function trialBalance(): TrialBalanceServiceInterface
    {
        return $this->trialBalanceService;
    }
}
