<?php

namespace App\Http\Controllers\Api\V1\Admin\Settings;

use App\Contracts\Settings\Services\BranchServiceInterface;
use App\Exceptions\Settings\BranchNotActiveException;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Settings\BranchResource;
use App\Models\Branch;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BranchController extends Controller
{
    public function __construct(private BranchServiceInterface $branches) {}

    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', Branch::class);

        return ApiResponse::success(
            'Branches retrieved successfully.',
            BranchResource::collection($this->branches->all()),
        );
    }

    public function show(Branch $branch): JsonResponse
    {
        Gate::authorize('view', $branch);

        return ApiResponse::success(
            'Branch retrieved successfully.',
            new BranchResource($branch),
        );
    }

    public function activate(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('activate', $branch);

        $activated = $this->branches->activate($branch, $request->user(), $request->ip());

        return ApiResponse::success(
            'Branch activated successfully.',
            new BranchResource($activated),
        );
    }

    public function makeDefault(Request $request, Branch $branch): JsonResponse
    {
        Gate::authorize('makeDefault', $branch);

        try {
            $default = $this->branches->makeDefault($branch, $request->user(), $request->ip());
        } catch (BranchNotActiveException $exception) {
            return ApiResponse::error($exception->getMessage(), 'BRANCH_NOT_ACTIVE', 422);
        }

        return ApiResponse::success(
            'Default branch updated successfully.',
            new BranchResource($default),
        );
    }
}
