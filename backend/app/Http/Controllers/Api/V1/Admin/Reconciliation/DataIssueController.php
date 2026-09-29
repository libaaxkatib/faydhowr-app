<?php

namespace App\Http\Controllers\Api\V1\Admin\Reconciliation;

use App\Actions\Reconciliation\CreateDataIssueAction;
use App\Actions\Reconciliation\GetDataIssueAction;
use App\Actions\Reconciliation\GetDataIssueSummaryAction;
use App\Actions\Reconciliation\ListDataIssuesAction;
use App\Actions\Reconciliation\ResolveDataIssueAction;
use App\Actions\Reconciliation\UpdateDataIssueAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Reconciliation\ListDataIssuesRequest;
use App\Http\Requests\Api\V1\Admin\Reconciliation\ResolveDataIssueRequest;
use App\Http\Requests\Api\V1\Admin\Reconciliation\StoreDataIssueRequest;
use App\Http\Requests\Api\V1\Admin\Reconciliation\UpdateDataIssueRequest;
use App\Http\Resources\Api\V1\Admin\Reconciliation\DataIssueResource;
use App\Models\DataIssue;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class DataIssueController extends Controller
{
    public function index(ListDataIssuesRequest $request, ListDataIssuesAction $action): JsonResponse
    {
        $paginator = $action->handle($request->validated());

        return ApiResponse::success(
            'Data issues retrieved successfully.',
            DataIssueResource::collection($paginator->items()),
            200,
            [
                'current_page' => $paginator->currentPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
                'last_page' => $paginator->lastPage(),
            ],
        );
    }

    public function store(StoreDataIssueRequest $request, CreateDataIssueAction $action): JsonResponse
    {
        $issue = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Data issue created successfully.', new DataIssueResource($issue), 201);
    }

    public function show(DataIssue $dataIssue, GetDataIssueAction $action): JsonResponse
    {
        $dataIssue = $action->handle($dataIssue);

        return ApiResponse::success('Data issue retrieved successfully.', new DataIssueResource($dataIssue));
    }

    public function update(UpdateDataIssueRequest $request, DataIssue $dataIssue, UpdateDataIssueAction $action): JsonResponse
    {
        $dataIssue = $action->handle($dataIssue, $request->validated(), $request->user());

        return ApiResponse::success('Data issue updated successfully.', new DataIssueResource($dataIssue));
    }

    public function resolve(ResolveDataIssueRequest $request, DataIssue $dataIssue, ResolveDataIssueAction $action): JsonResponse
    {
        $dataIssue = $action->handle($dataIssue, $request->validated(), $request->user());

        return ApiResponse::success('Data issue status updated successfully.', new DataIssueResource($dataIssue));
    }

    public function summary(GetDataIssueSummaryAction $action): JsonResponse
    {
        return ApiResponse::success('Data issue summary retrieved successfully.', $action->handle());
    }
}
