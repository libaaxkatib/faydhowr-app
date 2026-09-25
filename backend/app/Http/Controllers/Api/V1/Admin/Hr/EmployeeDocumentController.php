<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\DeleteEmployeeDocumentAction;
use App\Actions\Hr\StoreEmployeeDocumentAction;
use App\Actions\Hr\VerifyEmployeeDocumentAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeDocumentRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeDocumentResource;
use App\Models\Employee;
use App\Models\EmployeeDocument;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Storage;
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

    public function download(Employee $employee, EmployeeDocument $document): StreamedResponse|JsonResponse
    {
        if ((int) $document->employee_id !== (int) $employee->id) {
            return ApiResponse::error('Document was not found.', 'EMPLOYEE_DOCUMENT_NOT_FOUND', 404);
        }

        return Storage::disk('local')->download($document->file_path, $document->file_name);
    }

    public function destroy(Employee $employee, EmployeeDocument $document, DeleteEmployeeDocumentAction $action): JsonResponse
    {
        $action->handle($employee, $document, request()->user());

        return ApiResponse::success('Document deleted successfully.');
    }
}
