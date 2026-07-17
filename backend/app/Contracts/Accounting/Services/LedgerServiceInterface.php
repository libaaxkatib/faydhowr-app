<?php

namespace App\Contracts\Accounting\Services;

use App\DataTransferObjects\Accounting\LedgerBalanceData;
use App\Models\Account;

/**
 * Contract for the general ledger service of the Accounting module. The
 * ledger is derived on demand from posted journal entry lines; it is never
 * stored or cached.
 */
interface LedgerServiceInterface
{
    public function balanceForAccount(Account $account): LedgerBalanceData;
}
