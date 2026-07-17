<?php

namespace App\Http\Controllers\Api\V1\Admin\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Exceptions\Accounting\InvalidAccountingPeriodException;
use App\Exceptions\Accounting\OverlappingAccountingPeriodException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Accounting\StoreAccountingPeriodRequest;
use App\Http\Resources\Api\V1\Admin\Accounting\AccountingPeriodResource;
use App\Models\AccountingPeriod;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class AccountingPeriodController extends Controller
{
    public function __construct(private AccountingManagerInterface $accounting) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', AccountingPeriod::class);

        return ApiResponse::success(
            'Accounting periods retrieved successfully.',
            AccountingPeriodResource::collection($this->accounting->accountingPeriods()->all()),
        );
    }

    public function store(StoreAccountingPeriodRequest $request): JsonResponse
    {
        Gate::authorize('create', AccountingPeriod::class);

        try {
            $period = $this->accounting->accountingPeriods()->create(
                $request->periodName(),
                $request->startDate(),
                $request->endDate(),
            );
        } catch (OverlappingAccountingPeriodException $exception) {
            return ApiResponse::error($exception->getMessage(), 'OVERLAPPING_ACCOUNTING_PERIOD', 422);
        } catch (InvalidAccountingPeriodException $exception) {
            return ApiResponse::error($exception->getMessage(), 'INVALID_ACCOUNTING_PERIOD', 422);
        }

        return ApiResponse::success(
            'Accounting period created successfully.',
            new AccountingPeriodResource($period),
            201,
        );
    }
}
