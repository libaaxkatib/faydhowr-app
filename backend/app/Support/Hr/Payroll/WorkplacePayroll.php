<?php

namespace App\Support\Hr\Payroll;

use App\Models\ClientCompany;
use App\Models\PayrollRun;
use App\Models\PayrollWorkplacePayment;
use App\Models\WorkLocation;
use Illuminate\Support\Facades\DB;

/**
 * What each WORKPLACE (a Company or Guri, or one of its work locations) is owed and has been paid for a payroll month. Read-only: nothing is stored here.
 *
 *   total       = the net pay of the month's payable lines (net above zero, not voided) of the employees assigned to that workplace;
 *   paid        = lines already marked paid one by one (the existing "Mark as paid") + the workplace payments recorded for it that are not voided;
 *   outstanding = total - paid, never below zero;
 *   status      = unpaid (nothing paid) | partial (something paid, something still owed) | paid (nothing owed).
 *
 * A workplace is identified the way the employee's assignment identifies it: its company (or guri) and, when the assignment names one, its work location.
 * Each workplace is calculated on its own, so paying one never changes another. The payroll lines themselves are never changed by a workplace payment.
 */
final class WorkplacePayroll
{
    public const STATUS_UNPAID = 'unpaid';

    public const STATUS_PARTIAL = 'partial';

    public const STATUS_PAID = 'paid';

    /** Lines with no company and no work location cannot be attributed to a workplace; they stay payable line by line only. */
    public const NO_WORKPLACE = 'none';

    public static function key(?int $companyId, ?int $locationId): string
    {
        return $companyId === null && $locationId === null ? self::NO_WORKPLACE : ($companyId ?? 0).':'.($locationId ?? 0);
    }

    /**
     * The payable lines of a run as [item id, net cents, paid?, company id, location id, snapshot names].
     *
     * @return list<object>
     */
    public static function lines(PayrollRun $run): array
    {
        return DB::table('payroll_items as i')
            ->join('employee_work_assignments as a', 'a.id', '=', 'i.work_assignment_id')
            ->leftJoin('work_locations as l', 'l.id', '=', 'a.work_location_id')
            ->where('i.payroll_run_id', $run->id)
            ->whereNull('i.voided_at')
            ->where('i.net_cents', '>', 0)
            ->get(['i.id', 'i.net_cents', 'i.payment_status', 'i.company_name', 'i.workplace_name', 'a.work_location_id as location_id', DB::raw('COALESCE(a.client_company_id, l.client_company_id) as company_id')])
            ->all();
    }

    /**
     * Every workplace of the run, sorted by name, each with its own totals, status and payment history.
     *
     * @return list<array<string, mixed>>
     */
    public static function summaries(PayrollRun $run): array
    {
        $groups = [];

        foreach (self::lines($run) as $line) {
            $key = self::key($line->company_id === null ? null : (int) $line->company_id, $line->location_id === null ? null : (int) $line->location_id);
            $groups[$key] ??= ['company_id' => $line->company_id === null ? null : (int) $line->company_id, 'location_id' => $line->location_id === null ? null : (int) $line->location_id, 'company_name' => $line->company_name, 'workplace_name' => $line->workplace_name, 'employees' => 0, 'total' => 0, 'line_paid' => 0];
            $groups[$key]['employees']++;
            $groups[$key]['total'] += (int) $line->net_cents;
            $groups[$key]['line_paid'] += $line->payment_status === 'paid' ? (int) $line->net_cents : 0;
        }

        $payments = PayrollWorkplacePayment::query()->with(['recordedBy', 'voidedBy'])->where('payroll_run_id', $run->id)->orderBy('payment_date')->orderBy('id')->get();

        foreach ($payments as $payment) {
            $key = self::key($payment->client_company_id, $payment->work_location_id);
            // A payment for a workplace that has no payable line any more (an old run edited elsewhere) still shows up, with a zero total.
            $groups[$key] ??= ['company_id' => $payment->client_company_id, 'location_id' => $payment->work_location_id, 'company_name' => null, 'workplace_name' => null, 'employees' => 0, 'total' => 0, 'line_paid' => 0];
        }

        $companies = ClientCompany::query()->whereIn('id', array_filter(array_column($groups, 'company_id')))->get()->keyBy('id');
        $locations = WorkLocation::query()->whereIn('id', array_filter(array_column($groups, 'location_id')))->get()->keyBy('id');

        $out = [];

        foreach ($groups as $key => $g) {
            $company = $g['company_id'] === null ? null : $companies->get($g['company_id']);
            $location = $g['location_id'] === null ? null : $locations->get($g['location_id']);
            $own = $payments->filter(fn (PayrollWorkplacePayment $p): bool => self::key($p->client_company_id, $p->work_location_id) === $key);
            $recorded = (int) $own->whereNull('voided_at')->sum('amount_cents');
            $paid = $g['line_paid'] + $recorded;
            $outstanding = max(0, $g['total'] - $paid);

            $out[] = [
                'key' => $key,
                'payable' => $key !== self::NO_WORKPLACE,
                'client_company_id' => $g['company_id'],
                'work_location_id' => $g['location_id'],
                'company_name' => $company?->name ?? $g['company_name'],
                'workplace_name' => $location?->name ?? $g['workplace_name'],
                'category' => $company === null ? null : ($company->category?->value ?? 'company'),
                'employee_count' => $g['employees'],
                'total_cents' => $g['total'],
                'line_paid_cents' => $g['line_paid'],
                'workplace_paid_cents' => $recorded,
                'paid_cents' => $paid,
                'outstanding_cents' => $outstanding,
                'status' => self::status($g['total'], $paid),
                'payments' => $own->map(fn (PayrollWorkplacePayment $p): array => [
                    'id' => $p->id,
                    'amount' => PayrollMoney::format((int) $p->amount_cents),
                    'amount_cents' => (int) $p->amount_cents,
                    'payment_date' => $p->payment_date->toDateString(),
                    'note' => $p->note,
                    'recorded_by' => $p->recordedBy?->full_name,
                    'recorded_at' => $p->created_at?->toIso8601String(),
                    'voided' => $p->voided_at !== null,
                    'voided_at' => $p->voided_at?->toIso8601String(),
                    'void_reason' => $p->void_reason,
                    'voided_by' => $p->voidedBy?->full_name,
                ])->values()->all(),
            ];
        }

        usort($out, fn (array $a, array $b): int => [$a['payable'] ? 0 : 1, mb_strtolower((string) ($a['company_name'] ?? '')), mb_strtolower((string) ($a['workplace_name'] ?? ''))] <=> [$b['payable'] ? 0 : 1, mb_strtolower((string) ($b['company_name'] ?? '')), mb_strtolower((string) ($b['workplace_name'] ?? ''))]);

        return $out;
    }

    /** @return array<string, mixed>|null */
    public static function summaryFor(PayrollRun $run, ?int $companyId, ?int $locationId): ?array
    {
        $key = self::key($companyId, $locationId);

        foreach (self::summaries($run) as $summary) {
            if ($summary['key'] === $key) {
                return $summary;
            }
        }

        return null;
    }

    public static function status(int $total, int $paid): string
    {
        return match (true) {
            $paid <= 0 => self::STATUS_UNPAID,
            $paid >= $total => self::STATUS_PAID,
            default => self::STATUS_PARTIAL,
        };
    }

    /**
     * The same workplace of a single line, as [company id, location id] (the way {@see lines()} resolves it).
     *
     * @return array{0: ?int, 1: ?int}
     */
    public static function workplaceOfLine(int $payrollItemId): array
    {
        $row = DB::table('payroll_items as i')
            ->join('employee_work_assignments as a', 'a.id', '=', 'i.work_assignment_id')
            ->leftJoin('work_locations as l', 'l.id', '=', 'a.work_location_id')
            ->where('i.id', $payrollItemId)
            ->first([DB::raw('COALESCE(a.client_company_id, l.client_company_id) as company_id'), 'a.work_location_id as location_id']);

        return [$row?->company_id === null ? null : (int) $row->company_id, $row?->location_id === null ? null : (int) $row->location_id];
    }

    /**
     * Shapes the summary list for the screen: amounts as decimal strings (cents stay available), plus the run totals.
     *
     * @param  list<array<string, mixed>>  $summaries
     * @return array<string, mixed>
     */
    public static function present(PayrollRun $run, array $summaries): array
    {
        $payable = array_filter($summaries, fn (array $s): bool => $s['payable']);
        $sum = fn (string $field): int => (int) array_sum(array_column($payable, $field));

        return [
            'payroll_run_id' => $run->id,
            'period' => $run->period,
            'run_status' => $run->status,
            // Payments are recorded against a finalized month only, the same rule as marking a single line paid.
            'can_record' => $run->status === PayrollRun::STATUS_FINALIZED,
            'totals' => [
                'total' => PayrollMoney::format($sum('total_cents')),
                'paid' => PayrollMoney::format($sum('paid_cents')),
                'outstanding' => PayrollMoney::format($sum('outstanding_cents')),
            ],
            'workplaces' => array_map(fn (array $s): array => self::presentOne($s), $summaries),
        ];
    }

    /**
     * One workplace with its amounts as decimal strings next to the cents.
     *
     * @param  array<string, mixed>  $summary
     * @return array<string, mixed>
     */
    public static function presentOne(array $summary): array
    {
        if ($summary === []) {
            return [];
        }

        return $summary + [
            'total' => PayrollMoney::format($summary['total_cents']),
            'paid' => PayrollMoney::format($summary['paid_cents']),
            'outstanding' => PayrollMoney::format($summary['outstanding_cents']),
        ];
    }
}
