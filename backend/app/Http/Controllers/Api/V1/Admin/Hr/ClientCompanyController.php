<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateClientCompanyAction;
use App\Actions\Hr\DeleteClientCompanyAction;
use App\Actions\Hr\ListClientCompaniesAction;
use App\Actions\Hr\UpdateClientCompanyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\ListClientCompaniesRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreClientCompanyRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateClientCompanyRequest;
use App\Http\Resources\Api\V1\Admin\Hr\ClientCompanyResource;
use App\Models\ClientCompany;
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

    public function destroy(ClientCompany $clientCompany, DeleteClientCompanyAction $action): JsonResponse
    {
        $action->handle($clientCompany, request()->user());

        return ApiResponse::success('Client company deleted successfully.');
    }
}
