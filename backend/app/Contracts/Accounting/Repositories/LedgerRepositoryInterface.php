<?php

namespace App\Contracts\Accounting\Repositories;

use App\DataTransferObjects\Accounting\LedgerBalanceData;
use App\Models\Account;

/**
 * Read access to the general ledger. The ledger is never stored: it is
 * derived from the journal entry lines of posted journal entries, which
 * are the single source of truth.
 */
interface LedgerRepositoryInterface
{
    /**
     * Aggregate the posted debits and credits for the account and derive
     * its current balance from the account's normal balance side.
     */
    public function balanceForAccount(Account $account): LedgerBalanceData;
}
