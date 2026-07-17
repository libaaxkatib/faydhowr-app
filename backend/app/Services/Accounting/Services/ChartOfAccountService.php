<?php

namespace App\Services\Accounting\Services;

use App\Contracts\Accounting\Repositories\AccountRepositoryInterface;
use App\Contracts\Accounting\Services\ChartOfAccountServiceInterface;
use Illuminate\Database\Eloquent\Collection;

/**
 * Chart of accounts read service. Account management (create/update)
 * operations are implemented in later phases.
 */
class ChartOfAccountService implements ChartOfAccountServiceInterface
{
    public function __construct(
        private AccountRepositoryInterface $accountRepository,
    ) {}

    public function accounts(): Collection
    {
        return $this->accountRepository->all();
    }
}
