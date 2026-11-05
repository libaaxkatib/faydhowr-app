<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\ManageDeductionRecordAction;
use App\Http\Controllers\Controller;
use App\Http\Resources\Api\V1\Admin\Hr\EmployeeResource;
use App\Models\Employee;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\DeductionBalanceList;
use App\Support\Hr\Payroll\DeductionKind;
use App\Support\Hr\Payroll\DeductionRules;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * Void / correct / waive a penalty or a one-month advance, and the list of what is still owed (Release 1B; owner decisions R-1, P-1, A-1, Q6, Q7, Q8).
 * {kind} is "penalties" or "advances". Who may do what is decided in ManageDeductionRecordAction / CorrectionAuthority, never here.
 */
class DeductionRecordController extends Controller
{
    public function void(Request $request, Employee $employee, string $kind, int $record, ManageDeductionRecordAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), ['reason' => DeductionRules::reason(), 'expected_version' => ['required', 'integer', 'min:1']]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $deduction = $this->kind($kind);
        $action->void($deduction, $employee, $record, trim((string) $request->input('reason')), (int) $request->input('expected_version'), $request->user());

        return ApiResponse::success(ucfirst($deduction->label).' voided. It stays in the history and is no longer counted.', $this->employee($employee, $kind));
    }

    public function correct(Request $request, Employee $employee, string $kind, int $record, ManageDeductionRecordAction $action): JsonResponse
    {
        $deduction = $this->kind($kind);
        $amountField = $deduction->amountField;
        $dateField = $deduction->dateField;
        $validator = Validator::make($request->all(), [
            $amountField => DeductionRules::amount(false),
            $dateField => DeductionRules::date(false),
            'payroll_period' => DeductionRules::period(false),
            'reason' => DeductionRules::reason(false),
            'notes' => ['nullable', 'string', 'max:2000'],
            'client_company_id' => ['nullable', 'integer', 'exists:client_companies,id'],
            'correction_reason' => DeductionRules::reason(),
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $changes = array_intersect_key($request->only([$amountField, $dateField, 'payroll_period', 'reason', 'notes', 'client_company_id']), $validator->validated());

        $action->correct($deduction, $employee, $record, $changes, trim((string) $request->input('correction_reason')), (int) $request->input('expected_version'), $request->user());

        return ApiResponse::success(ucfirst($deduction->label).' corrected. The old row stays in the history as voided and the corrected one replaces it.', $this->employee($employee, $kind), 201);
    }

    public function waive(Request $request, Employee $employee, string $kind, int $record, ManageDeductionRecordAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'reason' => DeductionRules::reason(),
            'amount' => ['nullable', 'numeric', 'gt:0', 'regex:/^\d+(\.\d{1,2})?$/'],
            'expected_version' => ['required', 'integer', 'min:1'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $deduction = $this->kind($kind);
        $cents = $request->filled('amount') ? PayrollMoney::toCents($request->input('amount')) : null;
        $action->waive($deduction, $employee, $record, $cents, trim((string) $request->input('reason')), (int) $request->input('expected_version'), $request->user());

        return ApiResponse::success('The balance was waived. What was already deducted is unchanged.', $this->employee($employee, $kind));
    }

    /**
     * Everything still owed on penalties and advances (what payroll could not deduct yet), with the leavers marked. Needs hr.payroll.view: these are
     * salary amounts of named people. Read-only: nothing here is a debt or a payment. Paged on the server (`page`, `per_page` up to 200): `data.rows` is
     * the page, `data.totals` covers the whole filtered set and `meta` carries the real total and whether the safety ceiling truncated the scan.
     */
    public function balances(Request $request): JsonResponse
    {
        $validator = Validator::make($request->query(), [
            'client_company_id' => ['nullable', 'integer'],
            'kind' => ['nullable', 'in:penalty,advance'],
            'leavers_only' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.DeductionBalanceList::MAX_PER_PAGE],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $list = DeductionBalanceList::build(
            $request->filled('client_company_id') ? (int) $request->query('client_company_id') : null,
            $request->query('kind'),
            $request->boolean('leavers_only'),
            $request->integer('page', 1),
            $request->integer('per_page', DeductionBalanceList::DEFAULT_PER_PAGE),
        );

        return ApiResponse::success('Outstanding balances retrieved successfully.', ['rows' => $list['rows'], 'totals' => $list['totals']], 200, $list['meta']);
    }

    private function kind(string $kind): DeductionKind
    {
        return DeductionKind::of($kind === 'penalties' ? 'penalty' : 'advance');
    }

    private function employee(Employee $employee, string $kind): EmployeeResource
    {
        return new EmployeeResource($employee->load($kind === 'penalties'
            ? ['penalties.recordedBy', 'penalties.clientCompany']
            : ['advances.recordedBy', 'advances.clientCompany']));
    }
}
