<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\UploadEmployeeProfilePictureAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\StoreEmployeeProfilePictureRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;

class EmployeeProfilePictureController extends Controller
{
    public function store(StoreEmployeeProfilePictureRequest $request, Employee $employee, UploadEmployeeProfilePictureAction $action): JsonResponse
    {
        $employee = $action->handle($employee, $request->file('file'), $request->user());

        return ApiResponse::success('Profile picture updated successfully.', new EmployeeResource($employee));
    }
}
