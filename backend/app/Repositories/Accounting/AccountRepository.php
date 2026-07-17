<?php

namespace App\Repositories\Accounting;

use App\Contracts\Accounting\Repositories\AccountRepositoryInterface;
use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;

class AccountRepository implements AccountRepositoryInterface
{
    public function findById(int $id): ?Account
    {
        return Account::query()->find($id);
    }

    public function findByCode(string $code): ?Account
    {
        return Account::query()->where('code', $code)->first();
    }

    public function all(): Collection
    {
        return Account::query()->orderBy('code')->get();
    }
}
