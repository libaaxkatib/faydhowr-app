<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\CloseExpiredTemporaryReplacementAction;
use App\Actions\Hr\CreateTemporaryReplacementAction;
use App\Actions\Hr\EndTemporaryReplacementAction;
use App\Actions\Hr\ListTemporaryReplacementsAction;
use App\Actions\Hr\RecordTemporaryReplacementPaymentAction;
use App\Actions\Hr\UpdateTemporaryReplacementAction;
use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Admin\Hr\EndTemporaryReplacementRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreTemporaryReplacementPaymentRequest;
use App\Http\Requests\Api\V1\Admin\Hr\StoreTemporaryReplacementRequest;
use App\Http\Requests\Api\V1\Admin\Hr\UpdateTemporaryReplacementRequest;
use App\Http\Resources\Api\V1\Admin\Hr\TemporaryReplacementResource;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Support\AdminPermissionResolver;
use App\Support\ApiResponse;
use App\Support\Hr\CoverConflicts;
use App\Support\Hr\ReplacementChangeImpact;
use App\Support\Hr\ReplacementVersion;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TemporaryReplacementController extends Controller
{
    public function index(ListTemporaryReplacementsAction $action): JsonResponse
    {
        return ApiResponse::success('Temporary replacements retrieved successfully.', TemporaryReplacementResource::collection($action->handle()));
    }

    public function store(StoreTemporaryReplacementRequest $request, CreateTemporaryReplacementAction $action): JsonResponse
    {
        $workAssignment = EmployeeWorkAssignment::query()->findOrFail($request->validated('work_assignment_id'));
        $replacementEmployee = Employee::query()->findOrFail($request->validated('replacement_employee_id'));

        $replacement = $action->handle($workAssignment, $replacementEmployee, $request->safe()->except(['work_assignment_id', 'replacement_employee_id']), $request->user());

        return ApiResponse::success('Temporary replacement recorded successfully.', new TemporaryReplacementResource($replacement), 201);
    }

    /** Would this cover be refused? Never saves anything; the save runs the very same check again. */
    public function conflictCheck(Request $request): JsonResponse
    {
        $data = $request->validate([
            'work_assignment_id' => ['required', 'integer', 'exists:employee_work_assignments,id'],
            'replacement_employee_id' => ['required', 'integer', 'exists:employees,id'],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ]);

        $conflict = CoverConflicts::find(
            EmployeeWorkAssignment::query()->findOrFail($data['work_assignment_id']),
            Employee::query()->findOrFail($data['replacement_employee_id']),
            CarbonImmutable::parse($data['start_date'])->toDateString(),
            empty($data['end_date']) ? null : CarbonImmutable::parse($data['end_date'])->toDateString(),
        );

        return ApiResponse::success('Conflict check completed.', ['conflict' => $conflict]);
    }

    /**
     * Corrects the start date, the end date, the Temporary employee, the reason or the notes of a cover (never the covered assignment, a payment or the approval
     * by itself). Needs a written reason and the `version` the screen was showing.
     */
    public function update(UpdateTemporaryReplacementRequest $request, TemporaryReplacement $temporaryReplacement, UpdateTemporaryReplacementAction $action): JsonResponse
    {
        $replacement = $action->handle($temporaryReplacement, $request->validated(), $request->user());

        return ApiResponse::success('Temporary replacement corrected.', new TemporaryReplacementResource($replacement));
    }

    /**
     * What a change of the dates (or of the Temporary employee) would do, without saving anything: the days before and after, the cost of each month (for people
     * who may see payroll), which months are already approved or finalized, whether the approval would have to be given again, and whether the change would be
     * refused. The save runs the very same rules again.
     */
    public function changePreview(Request $request, TemporaryReplacement $temporaryReplacement, ReplacementChangeImpact $impact, AdminPermissionResolver $permissions): JsonResponse
    {
        $data = $request->validate([
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date'],
            'replacement_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
        ]);

        $new = [];

        if (! empty($data['start_date'])) {
            $new['start_date'] = CarbonImmutable::parse($data['start_date'])->toDateString();
        }

        if ($request->has('end_date')) {
            $new['end_date'] = empty($data['end_date']) ? null : CarbonImmutable::parse($data['end_date'])->toDateString();
        }

        $start = $new['start_date'] ?? $temporaryReplacement->start_date->toDateString();
        $end = array_key_exists('end_date', $new) ? $new['end_date'] : $temporaryReplacement->end_date?->toDateString();

        $seesMoney = $permissions->has($request->user(), AdminPermission::HrPayrollView->value, $request);
        $result = $impact->forChange($temporaryReplacement, $new, $seesMoney);

        $errors = [];

        if ($end !== null && $end < $start) {
            $errors[] = ['code' => 'TEMPORARY_REPLACEMENT_END_BEFORE_START', 'message' => 'The end date cannot be before the start date.'];
        }

        $employee = ! empty($data['replacement_employee_id']) ? Employee::query()->find($data['replacement_employee_id']) : $temporaryReplacement->replacementEmployee;
        $conflict = null;

        if ($errors === [] && $employee !== null) {
            $conflict = CoverConflicts::find($temporaryReplacement->workAssignment()->firstOrFail(), $employee, $start, $end, $temporaryReplacement->id);
        }

        $isSuperAdmin = $request->user()->role === AdminRole::SuperAdmin;
        $employeeLocked = (int) $employee?->id !== (int) $temporaryReplacement->replacement_employee_id
            && ($temporaryReplacement->payments()->exists() || $temporaryReplacement->workdays()->where('status', '!=', 'void')->exists());

        return ApiResponse::success('Change preview completed.', [
            ...$result,
            'errors' => $errors,
            'conflict' => $conflict,
            'employee_locked_by_records' => $employeeLocked,
            'requires_super_admin' => $result['changes_locked_period'] && ! $isSuperAdmin,
            'requires_reason_for_closed_payroll' => $result['changes_locked_period'],
            'approval_will_be_cleared' => $temporaryReplacement->approved_at !== null && $result['days_added'] > 0 && ! $result['touches_locked_period'],
            'is_approved' => $temporaryReplacement->approved_at !== null,
            'sees_money' => $seesMoney,
            'version' => ReplacementVersion::of($temporaryReplacement),
        ]);
    }

    public function end(EndTemporaryReplacementRequest $request, TemporaryReplacement $temporaryReplacement, EndTemporaryReplacementAction $action): JsonResponse
    {
        $replacement = $action->handle(
            $temporaryReplacement,
            $request->validated('end_date'),
            $request->validated('notes'),
            $request->user(),
            (bool) $request->validated('confirm_extension'),
            $request->validated('reason'),
            $request->validated('version'),
        );

        return ApiResponse::success('Temporary replacement coverage ended.', new TemporaryReplacementResource($replacement));
    }

    /** Closes a cover whose end date has passed, keeping its dates exactly as recorded (so no day and no cost can move). */
    public function close(TemporaryReplacement $temporaryReplacement, CloseExpiredTemporaryReplacementAction $action, Request $request): JsonResponse
    {
        $replacement = $action->handle($temporaryReplacement, $request->user());

        return ApiResponse::success('Temporary replacement closed.', new TemporaryReplacementResource($replacement));
    }

    /**
     * Who changed what, when and why, for this cover only (created, corrected, ended, closed, approved), newest first. Amounts in cents are shown only to people
     * who may see payroll.
     */
    public function history(Request $request, TemporaryReplacement $temporaryReplacement, AdminPermissionResolver $permissions): JsonResponse
    {
        $seesMoney = $permissions->has($request->user(), AdminPermission::HrPayrollView->value, $request);

        $rows = AuditLog::query()
            ->with('admin:id,full_name')
            ->where('entity_type', TemporaryReplacement::class)
            ->where('entity_id', $temporaryReplacement->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(200)
            ->get()
            ->map(fn (AuditLog $log): array => [
                'id' => $log->id,
                'action' => $log->action->value,
                'description' => $log->description,
                'by' => $log->admin?->full_name,
                'at' => $log->created_at?->toIso8601String(),
                'details' => $this->details($log->metadata ?? [], $seesMoney),
            ]);

        return ApiResponse::success('Temporary replacement history retrieved.', $rows);
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function details(array $metadata, bool $seesMoney): array
    {
        if ($seesMoney) {
            return $metadata;
        }

        return array_filter($metadata, fn (string $key): bool => ! str_ends_with($key, '_cents'), ARRAY_FILTER_USE_KEY);
    }

    public function addPayment(
        StoreTemporaryReplacementPaymentRequest $request,
        TemporaryReplacement $temporaryReplacement,
        RecordTemporaryReplacementPaymentAction $action,
        AdminPermissionResolver $permissions,
    ): JsonResponse {
        $result = $action->record($temporaryReplacement, $request->validated(), $request->user());

        // Calculated amounts are cost information (hr.payroll.view). A payer without it still gets the payment recorded, with the calculation kept.
        $seesCost = $permissions->has($request->user(), AdminPermission::HrPayrollView->value, $request);

        return ApiResponse::success('Payment recorded successfully.', [
            ...(new TemporaryReplacementResource($result['replacement']))->resolve($request),
            'payment_check' => $seesCost ? [
                'payment_id' => $result['check']['payment_id'],
                'calculated_amount' => $result['check']['calculated_amount'],
                'entered_amount' => $result['check']['entered_amount'],
                'differs' => $result['check']['differs'],
                'difference' => $result['check']['difference'],
                'warning' => $result['check']['warning'],
                'cost_approved' => $result['check']['calculation']['approved'] ?? null,
            ] : null,
        ], 201);
    }
}
