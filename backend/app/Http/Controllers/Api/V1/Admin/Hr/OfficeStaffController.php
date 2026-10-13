<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\AssignExistingEmployeeToOfficeStaffAction;
use App\Actions\Hr\RegisterOfficeStaffAction;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\AssignOfficeStaffRequest;
use App\Http\Requests\Api\V1\Admin\Hr\OfficeStaffLookupRequest;
use App\Http\Requests\Api\V1\Admin\Hr\RegisterOfficeStaffRequest;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeDuplicateFinder;
use App\Support\Hr\OfficeStaff\OfficeStaffSummary;
use Illuminate\Http\JsonResponse;

/**
 * The Office Staff workflow: find an existing employee, add an existing employee, or register a new one.
 * All three need hr.manage because they show and write emergency-contact, guarantor and salary details.
 * Documents are NOT handled here: they use the existing employee document endpoints (private storage,
 * authenticated download), so there is one upload path and one set of validation rules.
 */
class OfficeStaffController extends Controller
{
    public function lookup(OfficeStaffLookupRequest $request): JsonResponse
    {
        $term = trim($request->validated('search'));
        $like = '%'.mb_strtolower($term).'%';
        // Last nine digits, the same key duplicate detection uses, so "0615 777-888" finds "+252 615 777 888".
        $digits = EmployeeDuplicateFinder::phoneKey($term) ?? '';

        $employees = Employee::query()
            ->where(function ($q) use ($like, $digits): void {
                // LOWER(...) LIKE keeps this portable across PostgreSQL (production) and SQLite (tests).
                $q->whereRaw('LOWER(full_name) LIKE ?', [$like])
                    ->orWhereRaw('LOWER(employee_number) LIKE ?', [$like]);

                if (strlen($digits) >= 4) {
                    $q->orWhereRaw(EmployeeDuplicateFinder::digitsSql('phone').' LIKE ?', ['%'.$digits.'%']);
                }
            })
            ->orderBy('full_name')
            ->limit(10)
            ->get();

        return ApiResponse::success('Employees retrieved successfully.', $employees->map(fn (Employee $e): array => OfficeStaffSummary::for($e))->values()->all());
    }

    public function show(Employee $employee): JsonResponse
    {
        return ApiResponse::success('Employee retrieved successfully.', OfficeStaffSummary::for($employee));
    }

    public function assign(AssignOfficeStaffRequest $request, AssignExistingEmployeeToOfficeStaffAction $action): JsonResponse
    {
        $employee = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Employee added to Office Staff.', OfficeStaffSummary::for($employee));
    }

    public function register(RegisterOfficeStaffRequest $request, RegisterOfficeStaffAction $action): JsonResponse
    {
        $employee = $action->handle($request->validated(), $request->user());

        return ApiResponse::success('Office Staff registered.', OfficeStaffSummary::for($employee), 201);
    }
}
