<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\ListAttendanceForDateAction;
use App\Actions\Hr\MarkEmployeeAttendanceAction;
use App\Enums\AttendanceStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\MarkEmployeeAttendanceRequest;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeAttendanceResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmployeeAttendanceController extends Controller
{
    public function forDate(Request $request, ListAttendanceForDateAction $action): JsonResponse
    {
        $date = $request->query('date', now()->toDateString());

        return ApiResponse::success('Attendance retrieved successfully.', $action->handle($date));
    }

    public function mark(MarkEmployeeAttendanceRequest $request, Employee $employee, MarkEmployeeAttendanceAction $action): JsonResponse
    {
        $attendance = $action->handle(
            $employee,
            $request->validated('date'),
            AttendanceStatus::from($request->validated('status')),
            $request->validated('notes'),
            $request->user(),
        );

        return ApiResponse::success('Attendance recorded successfully.', new EmployeeAttendanceResource($attendance));
    }
}
