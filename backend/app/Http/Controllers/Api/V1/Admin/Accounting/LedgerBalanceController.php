<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class LedgerBalanceController extends Controller
{
    public function __construct(private AccountingManagerInterface $accounting) {}

    public function show(Account $account): JsonResponse
    {
        Gate::authorize('view', $account);

        return ApiResponse::success(
            'Ledger balance retrieved successfully.',
            $this->accounting->ledger()->balanceForAccount($account)->toArray(),
        );
    }
}
