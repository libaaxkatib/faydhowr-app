<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\DeleteEmployeeDocumentAction;
use App\Actions\Hr\StoreEmployeeDocumentAction;
use App\Actions\Hr\VerifyEmployeeDocumentAction;
use App\Enums\DocumentFileState;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeDocumentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeDocumentResource;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\ApiResponse;
use App\Support\Hr\Documents\DocumentFileInspector;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;

class EmployeeDocumentController extends Controller
{
    public function store(StoreEmployeeDocumentRequest $request, Employee $employee, StoreEmployeeDocumentAction $action): JsonResponse
    {
        $document = $action->handle($employee, $request->file('file'), $request->safe()->except('file'), $request->user());

        return ApiResponse::success('Document uploaded successfully.', new EmployeeDocumentResource($document), 201);
    }

    public function verify(Employee $employee, EmployeeDocument $document, VerifyEmployeeDocumentAction $action): JsonResponse
    {
        if ((int) $document->employee_id !== (int) $employee->id) {
            return ApiResponse::error('Document was not found.', 'EMPLOYEE_DOCUMENT_NOT_FOUND', 404);
        }

        $document = $action->handle($document, request()->user());

        return ApiResponse::success('Document verified successfully.', new EmployeeDocumentResource($document));
    }

    /**
     * The only way a stored employee document leaves the server.
     *
     * The employee and document ids are deliberately NOT route-model bound here. Laravel resolves bound models before
     * the route's permission check runs, so a signed-in caller without `hr.view` would get 404 for an id that does not
     * exist and 403 for one that does - revealing which documents exist. Looking the record up inside the controller
     * means the authentication and `hr.view` checks always answer first, with one identical refusal.
     *
     * For an authorized caller:
     * - file present                       -> the download
     * - no such document for this employee -> 404 EMPLOYEE_DOCUMENT_NOT_FOUND (one answer for every "not yours to find" case)
     * - file confirmed missing / unusable  -> 404 DOCUMENT_FILE_MISSING (nothing is recreated, changed or reported as recovered)
     * - storage could not be checked/read  -> 503 DOCUMENT_STORAGE_UNAVAILABLE (never claims the file is missing)
     * No message contains a path, an exception, a file name or any employee detail.
     */
    public function download(int $employee, int $document, DocumentFileInspector $files): StreamedResponse|JsonResponse
    {
        $record = EmployeeDocument::query()
            ->where('employee_id', $employee)
            ->whereHas('employee') // an employee who has been removed is not found, exactly as route binding treated them
            ->find($document);

        if ($record === null) {
            return ApiResponse::error('Document was not found.', 'EMPLOYEE_DOCUMENT_NOT_FOUND', 404);
        }

        $opened = $files->open($record->file_path);

        if ($opened instanceof DocumentFileState) {
            return $this->unavailable($opened, $record);
        }

        $headers = ['Content-Type' => $opened['mime'], 'X-Content-Type-Options' => 'nosniff'];

        if ($opened['size'] !== null) {
            $headers['Content-Length'] = (string) $opened['size'];
        }

        // The file is already open, so it cannot vanish half-way through the transfer.
        $response = response()->streamDownload(function () use ($opened): void {
            try {
                fpassthru($opened['stream']);
            } finally {
                fclose($opened['stream']);
            }
        }, $record->file_name, $headers);

        // Identity and personal documents: never stored by a browser or shared cache.
        $response->headers->set('Cache-Control', 'no-store, private');

        return $response;
    }

    public function destroy(Employee $employee, EmployeeDocument $document, DeleteEmployeeDocumentAction $action): JsonResponse
    {
        $action->handle($employee, $document, request()->user());

        return ApiResponse::success('Document deleted successfully.');
    }

    private function unavailable(DocumentFileState $state, EmployeeDocument $document): JsonResponse
    {
        // Internal identifiers and the reason only: no path, file name, contents or personal details.
        $context = [
            'document_id' => $document->id,
            'employee_id' => $document->employee_id,
            'reason' => $state->value,
        ];

        if ($state->isPermanentlyUnavailable()) {
            Log::warning('Employee document file is not available.', $context);

            return ApiResponse::error(
                'This document is not available. Please ask the employee to provide it again and upload a new copy.',
                'DOCUMENT_FILE_MISSING',
                404,
            );
        }

        Log::error('Employee document storage could not be read.', $context);

        return ApiResponse::error(
            'Document storage is temporarily unavailable. Please try again in a few minutes.',
            'DOCUMENT_STORAGE_UNAVAILABLE',
            503,
        )->header('Retry-After', '60');
    }
}
