<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Accounting\AccountingReportRequest;
use App\Models\Account;
use App\Models\AccountingPeriod;
use Illuminate\Support\Facades\Gate;

/**
 * Shared behavior for the derived accounting report endpoints (trial
 * balance and financial statements): every report is authorized through
 * the account policy and accepts either an accounting period id or a
 * date range, resolved here so subclasses only invoke their service.
 */
abstract class AccountingReportController extends Controller
{
    public function __construct(protected AccountingManagerInterface $accounting) {}

    protected function authorizeReport(): void
    {
        Gate::authorize('viewAny', Account::class);
    }

    protected function resolvePeriod(AccountingReportRequest $request): ?AccountingPeriod
    {
        $periodId = $request->periodId();

        if ($periodId === null) {
            return null;
        }

        return $this->accounting->accountingPeriods()->findById($periodId);
    }
}
