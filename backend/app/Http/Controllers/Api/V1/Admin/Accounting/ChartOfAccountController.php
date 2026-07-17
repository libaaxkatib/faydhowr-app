<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Accounting\AccountResource;
use App\Models\Account;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ChartOfAccountController extends Controller
{
    public function __construct(private AccountingManagerInterface $accounting) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Account::class);

        return ApiResponse::success(
            'Accounts retrieved successfully.',
            AccountResource::collection($this->accounting->chartOfAccounts()->accounts()),
        );
    }
}
