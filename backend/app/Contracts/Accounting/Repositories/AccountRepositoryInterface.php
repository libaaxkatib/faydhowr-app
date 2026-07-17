<?php

namespace App\Contracts\Accounting\Repositories;

use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;

/**
 * Read access to the chart of accounts. CRUD operations and filtering are
 * added in later phases.
 */
interface AccountRepositoryInterface
{
    public function findById(int $id): ?Account;

    public function findByCode(string $code): ?Account;

    /**
     * Every account ordered by code.
     *
     * @return Collection<int, Account>
     */
    public function all(): Collection;
}
