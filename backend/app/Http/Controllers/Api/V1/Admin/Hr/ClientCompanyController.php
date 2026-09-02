<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CreateClientCompanyAction;
use App\Actions\Hr\DeleteClientCompanyAction;
use App\Actions\Hr\ListClientCompaniesAction;
use App\Actions\Hr\UpdateClientCompanyAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreClientCompanyRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateClientCompanyRequest;
use App\Http\Resources\Api\V1\Admin\Hr\ClientCompanyResource;
use App\Models\ClientCompany;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class ClientCompanyController extends Controller
{
    public function index(ListClientCompaniesAction $action): JsonResponse
    {
        return ApiResponse::success(
            'Client companies retrieved successfully.',
            ClientCompanyResource::collection($action->handle()),
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
