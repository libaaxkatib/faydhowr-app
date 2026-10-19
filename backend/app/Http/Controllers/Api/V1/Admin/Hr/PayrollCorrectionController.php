<?php

namespace App\Http\Controllers\Api\V1\Admin\Hr;

use App\Http\Controllers\Controller;
use App\Models\PayrollCorrection;
use App\Models\PayrollRecoveryDeduction;
use App\Support\ApiResponse;
use App\Support\Hr\Payroll\PayrollMoney;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Read-only view of the payroll corrections (Super Admin corrections of approved/finalized payroll) and of the ledger that recovers
 * overpayments from later salaries. Needs hr.payroll.view; it changes nothing.
 */
class PayrollCorrectionController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $corrections = PayrollCorrection::query()
            ->with(['employee:id,employee_number,full_name', 'correctedBy:id,full_name', 'recoveries'])
            ->when($request->filled('employee_id'), fn ($q) => $q->where('employee_id', (int) $request->query('employee_id')))
            ->when($request->filled('status'), fn ($q) => $q->where('status', (string) $request->query('status')))
            ->when($request->filled('period'), fn ($q) => $q->where('period', (string) $request->query('period')))
            ->orderByDesc('id')
            ->limit(500)
            ->get();

        $rows = $corrections->map(function (PayrollCorrection $c): array {
            $applied = (int) $c->recoveries->where('status', PayrollRecoveryDeduction::STATUS_APPLIED)->sum('amount_cents');
            $scheduled = (int) $c->recoveries->where('status', PayrollRecoveryDeduction::STATUS_SCHEDULED)->sum('amount_cents');

            return [
                'id' => $c->id,
                'employee_id' => $c->employee_id,
                'employee_number' => $c->employee?->employee_number,
                'employee_name' => $c->employee?->full_name,
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
                'outstanding_cents' => (int) $c->outstanding_cents,
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
            ];
        })->values();

        return ApiResponse::success('Payroll corrections retrieved successfully.', [
            'corrections' => $rows,
            'totals' => [
                'currency' => PayrollMoney::CURRENCY,
                'overpaid_cents' => (int) $rows->where('kind', PayrollCorrection::KIND_OVERPAYMENT)->sum('difference_cents'),
                'recovered_cents' => (int) $rows->where('kind', PayrollCorrection::KIND_OVERPAYMENT)->sum('recovered_cents'),
                'outstanding_cents' => (int) $rows->where('kind', PayrollCorrection::KIND_OVERPAYMENT)->sum('outstanding_cents'),
                'underpayments_pending_policy_cents' => (int) abs($rows->where('kind', PayrollCorrection::KIND_UNDERPAYMENT)->sum('difference_cents')),
            ],
        ]);
    }
}
