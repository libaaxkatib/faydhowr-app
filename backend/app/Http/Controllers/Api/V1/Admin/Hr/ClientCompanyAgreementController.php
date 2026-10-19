<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\StoreClientCompanyAgreementAction;
use App\Enums\DocumentFileState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreClientCompanyAgreementRequest;
use App\Http\Resources\Api\V1\Admin\Hr\ClientCompanyAgreementResource;
use App\Models\ClientCompany;
use App\Models\ClientCompanyAgreement;
use App\Support\ApiResponse;
use App\Support\Hr\Documents\DocumentFileInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
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

    public function download(ClientCompany $clientCompany, ClientCompanyAgreement $agreement, DocumentFileInspector $files): StreamedResponse|JsonResponse
    {
        if ((int) $agreement->client_company_id !== (int) $clientCompany->id) {
            return ApiResponse::error('Agreement not found for this company.', 'AGREEMENT_NOT_FOUND', 404);
        }

        // The row names the disk its file lives on, so a later change of the configured disk strands nothing.
        $files = $files->forDisk($agreement->disk ?: 'local');

        // An agreement with a recorded checksum is hashed before it is sent: a damaged or altered file is never served.
        if ($agreement->sha256 !== null && (bool) config('filesystems.hr_documents_verify_on_download', true)) {
            $integrity = $files->inspect($agreement->file_path, null, $agreement->sha256);

            if (! $integrity->isServable()) {
                return $this->unavailable($integrity, $agreement);
            }
        }

        $opened = $files->open($agreement->file_path);

        if ($opened instanceof DocumentFileState) {
            return $this->unavailable($opened, $agreement);
        }

        $headers = ['Content-Type' => $opened['mime'], 'X-Content-Type-Options' => 'nosniff'];

        if ($opened['size'] !== null) {
            $headers['Content-Length'] = (string) $opened['size'];
        }

        $response = response()->streamDownload(function () use ($opened): void {
            try {
                fpassthru($opened['stream']);
            } finally {
                fclose($opened['stream']);
            }
        }, $agreement->file_name, $headers);

        // Company contracts: never stored by a browser or shared cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    private function unavailable(DocumentFileState $state, ClientCompanyAgreement $agreement): JsonResponse
    {
        // Internal identifiers and the reason only: no path, file name, contents or company details.
        $context = ['agreement_id' => $agreement->id, 'client_company_id' => $agreement->client_company_id, 'reason' => $state->value];

        if ($state->isPermanentlyUnavailable()) {
            Log::warning('Company agreement file is not available.', $context);

            return ApiResponse::error('The agreement file is no longer available in storage.', 'AGREEMENT_FILE_MISSING', 404);
        }

        if ($state->isIntegrityFailure()) {
            Log::error('Company agreement failed its integrity check and was not sent.', $context);

            return ApiResponse::error(
                'This agreement failed an integrity check and was not sent. Please contact an administrator.',
                'AGREEMENT_INTEGRITY_FAILED',
                502,
            );
        }

        Log::error('Company agreement storage could not be read.', $context);

        return ApiResponse::error(
            'Agreement storage is temporarily unavailable. Please try again in a few minutes.',
            'AGREEMENT_STORAGE_UNAVAILABLE',
            503,
        )->header('Retry-After', '60');
    }
}
