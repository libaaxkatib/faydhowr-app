<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\Company\CloseCompanyAction;
use App\Actions\Hr\Company\ReopenCompanyAction;
use App\Actions\Hr\CreateClientCompanyAction;
use App\Actions\Hr\DeleteClientCompanyAction;
use App\Actions\Hr\ListClientCompaniesAction;
use App\Actions\Hr\UpdateClientCompanyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\CloseCompanyRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ListClientCompaniesRequest;
use App\Http\Requests\Api\V1\Admin\Hr\ReopenCompanyRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreClientCompanyRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateClientCompanyRequest;
use App\Http\Resources\Api\V1\Admin\Hr\ClientCompanyResource;
use App\Models\ClientCompany;
use App\Models\CompanyClosure;
use App\Support\ApiResponse;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\JsonResponse;

class ClientCompanyController extends Controller
{
    public function index(ListClientCompaniesRequest $request, ListClientCompaniesAction $action): JsonResponse
    {
        $filters = $request->validated();
        $result = $action->handle($filters);

        if (! $result instanceof LengthAwarePaginator) {
            return ApiResponse::success('Client companies retrieved successfully.', ClientCompanyResource::collection($result));
        }

        return ApiResponse::success(
            'Client companies retrieved successfully.',
            ClientCompanyResource::collection($result->items()),
            200,
            [
                'current_page' => $result->currentPage(),
                'per_page' => $result->perPage(),
                'total' => $result->total(),
                'last_page' => $result->lastPage(),
                ...$action->summary($filters),
            ],
        );
    }

    public function store(StoreClientCompanyRequest $request, CreateClientCompanyAction $action): JsonResponse
    {
        $clientCompany = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Client company created successfully.', new ClientCompanyResource($clientCompany), 201);
    }

    public function update(UpdateClientCompanyRequest $request, ClientCompany $clientCompany, UpdateClientCompanyAction $action): JsonResponse
    {
        $clientCompany = $action->handle($clientCompany, $request->validated(), $request->user());

        return ApiResponse::success('Client company updated successfully.', new ClientCompanyResource($clientCompany));
    }

    /** Close a company / facility that stopped operating (effective date + reason); its assignments end, nothing is deleted. */
    public function close(CloseCompanyRequest $request, ClientCompany $clientCompany, CloseCompanyAction $action): JsonResponse
    {
        $closure = $action->handle($clientCompany, $request->validated(), $request->user());

        return ApiResponse::success('Company closed. Its assignments ended on the closing day; payroll, payments and history are kept.', $this->closureView($closure), 201);
    }

    /** What a reopening would restore: every assignment the open closure ended or cancelled, restorable or with the reason it is not. */
    public function closurePreview(ClientCompany $clientCompany): JsonResponse
    {
        $closure = ReopenCompanyAction::openClosure($clientCompany);

        return ApiResponse::success('Closure retrieved successfully.', $closure === null ? ['closed' => false, 'history' => $this->closureHistory($clientCompany)] : [...$this->closureView($closure), 'assignments' => ReopenCompanyAction::preview($closure), 'history' => $this->closureHistory($clientCompany)]);
    }

    /** Reopen a company closed by mistake; only the confirmed assignments of THAT closure are restored. */
    public function reopen(ReopenCompanyRequest $request, ClientCompany $clientCompany, ReopenCompanyAction $action): JsonResponse
    {
        $closure = $action->handle($clientCompany, $request->validated(), $request->user());

        return ApiResponse::success('Company reopened.', $this->closureView($closure));
    }

    /** @return array<string, mixed> */
    private function closureView(CompanyClosure $closure): array
    {
        return [
            'closed' => $closure->status === CompanyClosure::STATUS_CLOSED,
            'closure_id' => $closure->id,
            'status' => $closure->status,
            'effective_date' => $closure->effective_date->toDateString(),
            'reason' => $closure->reason,
            'closed_by' => $closure->closedBy?->full_name,
            'closed_by_role' => $closure->closed_by_role,
            'closed_at' => $closure->created_at?->toIso8601String(),
            'summary' => $closure->summary,
            'reopened_at' => $closure->reopened_at?->toIso8601String(),
            'reopened_by' => $closure->reopenedBy?->full_name,
            'reopen_reason' => $closure->reopen_reason,
            'reopen_summary' => $closure->reopen_summary,
        ];
    }

    /** @return list<array<string, mixed>> */
    private function closureHistory(ClientCompany $company): array
    {
        return CompanyClosure::query()->with(['closedBy', 'reopenedBy'])->where('client_company_id', $company->id)->orderByDesc('id')->get()->map(fn (CompanyClosure $c): array => $this->closureView($c))->all();
    }

    public function destroy(ClientCompany $clientCompany, DeleteClientCompanyAction $action): JsonResponse
    {
        $action->handle($clientCompany, request()->user());

        return ApiResponse::success('Client company deleted successfully.');
    }
}
