<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\StoreClientCompanyAgreementAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreClientCompanyAgreementRequest;
use App\Http\Resources\Api\V1\Admin\Hr\ClientCompanyAgreementResource;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAgreement;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ClientCompanyAgreementController extends Controller
{
    public function index(ClientCompany $clientCompany): JsonResponse
    {
        return ApiResponse::success(
            'Company agreements retrieved successfully.',
            ClientCompanyAgreementResource::collection($clientCompany->agreements()->with('uploadedBy')->get()),
        );
    }

    public function store(StoreClientCompanyAgreementRequest $request, ClientCompany $clientCompany, StoreClientCompanyAgreementAction $action): JsonResponse
    {
        $agreement = $action->handle($clientCompany, $request->file('file'), $request->validated('notes'), $request->user());

        return ApiResponse::success('Company agreement uploaded successfully.', new ClientCompanyAgreementResource($agreement->load('uploadedBy')), 201);
    }

    public function download(ClientCompany $clientCompany, ClientCompanyAgreement $agreement): StreamedResponse|JsonResponse
    {
        if ((int) $agreement->client_company_id !== (int) $clientCompany->id) {
            return ApiResponse::error('Agreement not found for this company.', 'AGREEMENT_NOT_FOUND', 404);
        }

        $disk = Storage::disk($agreement->disk);

        if (! $disk->exists($agreement->file_path)) {
            return ApiResponse::error('The agreement file is no longer available in storage.', 'AGREEMENT_FILE_MISSING', 404);
        }

        return $disk->download($agreement->file_path, $agreement->file_name);
    }
}
