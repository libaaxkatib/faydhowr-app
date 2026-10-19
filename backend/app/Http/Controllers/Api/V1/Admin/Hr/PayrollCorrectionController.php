<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Actions\Hr\RecordCorrectionTransactionAction;
use App\Actions\Hr\VoidCorrectionTransactionAction;
use App\Enums\WorkAssignmentStatus;
use App\Http\Controllers\Controller;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollCorrectionTransaction;
use App\Models\PayrollRecoveryDeduction;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

/**
 * The corrections ledger: Super Admin corrections of approved/finalized payroll, the overpayments recovered from later salaries, the
 * debts that remain, and the underpayments owed. Reading needs hr.payroll.view. Recording a payment or repayment needs hr.payroll.manage
 * AND the right role (checked again in the action): Super Admin pays underpayments; Super Admin and HR Manager manage debts.
 */
class PayrollCorrectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $corrections = PayrollCorrection::query()
            ->with(['employee:id,employee_number,full_name,status', 'correctedBy:id,full_name', 'recoveries', 'transactions.recordedBy:id,full_name', 'transactions.voidedBy:id,full_name'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', (int) $request->query('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('period'), fn ($q) => $q->where('period', (string) $request->query('period')))
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $openEmployees = EmployeeWorkAssignment::query()
            ->whereIn('employee_id', $corrections->pluck('employee_id')->unique()->all())
            ->where('status', WorkAssignmentStatus::Active)->whereNull('end_date')
            ->pluck('employee_id')->flip();

        $rows = $corrections->map(function (PayrollCorrection $c) use ($openEmployees): array {
            $applied = (int) $c->recoveries->where('status', PayrollRecoveryDeduction::STATUS_APPLIED)->sum('amount_cents');
            $scheduled = (int) $c->recoveries->where('status', PayrollRecoveryDeduction::STATUS_SCHEDULED)->sum('amount_cents');
            $active = $c->transactions->whereNull('voided_at');
            $settled = (int) $active->sum('amount_cents');
            $hasOpenAssignment = $openEmployees->has($c->employee_id);

            return [
                'id' => $c->id,
                'employee_id' => $c->employee_id,
                'employee_number' => $c->employee?->employee_number,
                'employee_name' => $c->employee?->full_name,
                'employee_status' => $c->employee?->status->value,
                'has_open_assignment' => $hasOpenAssignment,
                // A debt that payroll can no longer collect (nobody is being paid): somebody has to follow it up.
                'needs_follow_up' => $c->kind === PayrollCorrection::KIND_OVERPAYMENT && $c->outstanding_cents > 0 && ! $hasOpenAssignment,
                'period' => $c->period,
                'kind' => $c->kind,
                'action' => $c->action,
                'status' => $c->status,
                'currency' => PayrollMoney::CURRENCY,
                'recorded_net_cents' => (int) $c->recorded_net_cents,
                'corrected_net_cents' => (int) $c->corrected_net_cents,
                'difference_cents' => (int) $c->difference_cents,
                'recovered_cents' => $applied,
                'scheduled_cents' => $scheduled,
                'repaid_cents' => $c->kind === PayrollCorrection::KIND_OVERPAYMENT ? $settled : 0,
                'paid_cents' => $c->kind === PayrollCorrection::KIND_UNDERPAYMENT ? $settled : 0,
                'outstanding_cents' => (int) $c->outstanding_cents,
                'available_to_settle_cents' => max(0, (int) $c->outstanding_cents - ($c->kind === PayrollCorrection::KIND_OVERPAYMENT ? $scheduled : 0)),
                'reason' => $c->reason,
                'old_values' => $c->old_values,
                'new_values' => $c->new_values,
                'corrected_by' => $c->correctedBy?->full_name,
                'corrected_at' => $c->created_at?->toIso8601String(),
                'recoveries' => $c->recoveries->map(fn (PayrollRecoveryDeduction $r): array => [
                    'period' => $r->period,
                    'amount_cents' => (int) $r->amount_cents,
                    'status' => $r->status,
                ])->values()->all(),
                'transactions' => $c->transactions->map(fn (PayrollCorrectionTransaction $t): array => [
                    'id' => $t->id,
                    'type' => $t->type,
                    'amount_cents' => (int) $t->amount_cents,
                    'transaction_date' => $t->transaction_date?->toDateString(),
                    'note' => $t->note,
                    'recorded_by' => $t->recordedBy?->full_name,
                    'voided' => $t->isVoided(),
                    'void_reason' => $t->void_reason,
                    'voided_by' => $t->voidedBy?->full_name,
                ])->values()->all(),
            ];
        })->values();

        $over = $rows->where('kind', PayrollCorrection::KIND_OVERPAYMENT);
        $under = $rows->where('kind', PayrollCorrection::KIND_UNDERPAYMENT);

        return ApiResponse::success('Payroll corrections retrieved successfully.', [
            'corrections' => $rows,
            'totals' => [
                'currency' => PayrollMoney::CURRENCY,
                'overpaid_cents' => (int) $over->sum('difference_cents'),
                'recovered_cents' => (int) ($over->sum('recovered_cents') + $over->sum('repaid_cents')),
                'outstanding_cents' => (int) $over->sum('outstanding_cents'),
                'underpaid_cents' => (int) abs($under->sum('difference_cents')),
                'underpayments_paid_cents' => (int) $under->sum('paid_cents'),
                'underpayments_outstanding_cents' => (int) $under->sum('outstanding_cents'),
            ],
        ]);
    }

    /** Records a payment of an underpayment (Super Admin) or a repayment of a debt (Super Admin / HR Manager). */
    public function store(Request $request, PayrollCorrection $payrollCorrection, RecordCorrectionTransactionAction $action): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'type' => ['required', 'in:'.PayrollCorrectionTransaction::TYPE_UNDERPAYMENT_PAYMENT.','.PayrollCorrectionTransaction::TYPE_DEBT_REPAYMENT],
            'amount' => ['required', 'numeric', 'gt:0', 'max:9999999999.99'],
            'transaction_date' => ['required', 'date', 'before_or_equal:today'],
            'note' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'min:8', 'max:64'],
        ]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $result = $action->handle(
            $payrollCorrection,
            (string) $request->input('type'),
            PayrollMoney::toCents($request->input('amount')),
            (string) $request->input('transaction_date'),
            $request->filled('note') ? (string) $request->input('note') : null,
            (string) $request->input('idempotency_key'),
            $request->user(),
        );

        return ApiResponse::success($result['replayed'] ? 'This transaction was already recorded; nothing was added.' : 'Transaction recorded.', [
            'transaction_id' => $result['transaction']->id,
            'replayed' => $result['replayed'],
            'outstanding_cents' => (int) $result['correction']->outstanding_cents,
            'status' => $result['correction']->status,
        ], $result['replayed'] ? 200 : 201);
    }

    public function void(Request $request, PayrollCorrection $payrollCorrection, PayrollCorrectionTransaction $transaction, VoidCorrectionTransactionAction $action): JsonResponse
    {
        if ((int) $transaction->payroll_correction_id !== (int) $payrollCorrection->id) {
            abort(404);
        }

        $validator = Validator::make($request->all(), ['reason' => ['required', 'string', 'min:5', 'max:500']]);

        if ($validator->fails()) {
            return ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors());
        }

        $correction = $action->handle($transaction, (string) $request->input('reason'), $request->user());

        return ApiResponse::success('Repayment voided. The debt is restored.', ['outstanding_cents' => (int) $correction->outstanding_cents, 'status' => $correction->status]);
    }
}
