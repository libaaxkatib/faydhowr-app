<?php

namespace App\Services\Accounting\Services;

use App\Contracts\Accounting\Repositories\LedgerRepositoryInterface;
use App\Contracts\Accounting\Services\LedgerServiceInterface;
use App\DataTransferObjects\Accounting\LedgerBalanceData;
use App\Models\Account;

/**
 * General ledger read service. All queries and balance calculations are
 * owned by the ledger repository.
 */
class LedgerService implements LedgerServiceInterface
{
    public function __construct(
        private LedgerRepositoryInterface $ledgerRepository,
    ) {}

    public function balanceForAccount(Account $account): LedgerBalanceData
    {
        return $this->ledgerRepository->balanceForAccount($account);
    }
}
