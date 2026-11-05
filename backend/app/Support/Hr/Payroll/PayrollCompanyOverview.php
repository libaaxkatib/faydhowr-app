<?php

namespace App\Support\Hr\Payroll;

use App\Models\ClientCompany;
use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * The company-level view of a payroll month (owner decisions C-6, C-7, N-4, N-4a). Read-only: nothing is stored here and nothing is calculated that the
 * payroll does not already hold.
 *
 *   assigned companies = every company (or guri) with a non-cancelled assignment that overlaps the month, plus any company that already has a section
 *                        (stored payroll is never hidden). "No company" appears only when lines exist that name none.
 *   state              = not_generated | draft | reviewed | finalized | partially_paid | paid | overpaid
 *                        (a legacy "approved" section is shown as reviewed with legacy_approved = true: it is locked but cannot be paid before it is finalized)
 *   nothing_payable    = the company was generated and holds no payable line (shown as "Generated - Nothing Payable", owner decision D-3)
 *
 * A company that was not generated has NO payroll total (null, shown "-"): a missing generation is never a zero, an estimate or a payment, and it keeps the
 * month from being "All Companies Paid".
 */
final class PayrollCompanyOverview
{
    public const NOT_GENERATED = 'not_generated';

    public const DRAFT = 'draft';

    public const REVIEWED = 'reviewed';

    public const FINALIZED = 'finalized';

    public const PARTIALLY_PAID = 'partially_paid';

    public const PAID = 'paid';

    public const OVERPAID = 'overpaid';

    /**
     * The companies assigned to the month: [company id => true]; the key 0 stands for "no company".
     *
     * @return array<int, true>
     */
    public static function assignedCompanyIds(string $period): array
    {
        $first = CarbonImmutable::parse($period.'-01')->startOfDay();
        $rows = DB::table('employee_work_assignments as a')
            ->leftJoin('work_locations as l', 'l.id', '=', 'a.work_location_id')
            ->where('a.status', '!=', 'cancelled')
            ->whereDate('a.start_date', '<=', $first->endOfMonth()->toDateString())
            ->where(fn ($q) => $q->whereNull('a.end_date')->orWhereDate('a.end_date', '>=', $first->toDateString()))
            ->distinct()
            ->get([DB::raw('COALESCE(a.client_company_id, l.client_company_id) as company_id')]);

        $ids = [];

        foreach ($rows as $row) {
            $ids[$row->company_id === null ? 0 : (int) $row->company_id] = true;
        }

        return $ids;
    }

    /**
     * @return array{period: string, run_id: ?int, companies: list<array<string, mixed>>, summary: array<string, mixed>}
     */
    public static function forPeriod(string $period): array
    {
        $run = PayrollRun::query()->where('period', $period)->first();
        $states = $run === null ? [] : collect(PayrollApproval::sections($run))->keyBy(fn (array $s): int => $s['client_company_id'] ?? 0)->all();
        $sections = $run === null ? collect() : $run->sections()->get()->keyBy(fn (PayrollRunSection $s): int => $s->client_company_id ?? 0);

        $assigned = self::assignedCompanyIds($period);
        // A company that holds a section is listed even when it no longer has an assignment this month; the "no company" key only when its section exists or lines name none.
        $ids = array_values(array_unique([...array_keys($assigned), ...$sections->keys()->all()]));

        $companies = ClientCompany::query()->whereIn('id', array_filter($ids))->get()->keyBy('id');
        $rows = [];

        foreach ($ids as $id) {
            $section = $sections->get($id);
            $state = $states[$id] ?? null;
            $company = $id === 0 ? null : $companies->get($id);

            $rows[] = self::row($id, $company, $section, $state);
        }

        usort($rows, fn (array $a, array $b): int => [$a['client_company_id'] === null ? 1 : 0, mb_strtolower((string) $a['company_name'])] <=> [$b['client_company_id'] === null ? 1 : 0, mb_strtolower((string) $b['company_name'])]);

        return ['period' => $period, 'run_id' => $run?->id, 'companies' => $rows, 'summary' => self::summary($rows)];
    }

    /**
     * @param  array<string, mixed>|null  $payment  the company's PayrollApproval state
     * @return array<string, mixed>
     */
    private static function row(int $id, ?ClientCompany $company, ?PayrollRunSection $section, ?array $payment): array
    {
        $generated = $section !== null;
        $state = self::NOT_GENERATED;
        $nothingPayable = false;

        if ($generated) {
            $nothingPayable = (int) (($section->totals ?? [])['item_count'] ?? 0) === 0;
            $state = match (true) {
                $section->status === PayrollRunSection::STATUS_FINALIZED => match ($payment['payment_status'] ?? PayrollApproval::UNPAID) {
                    PayrollApproval::OVERPAID => self::OVERPAID,
                    PayrollApproval::APPROVED => self::PAID,
                    PayrollApproval::PARTIAL => self::PARTIALLY_PAID,
                    default => self::FINALIZED,
                },
                $section->status === PayrollRunSection::STATUS_DRAFT => self::DRAFT,
                default => self::REVIEWED,
            };
        }

        $total = $generated ? (int) ($payment['total_cents'] ?? 0) : null;
        $paid = $generated ? (int) ($payment['paid_cents'] ?? 0) : null;
        $outstanding = $generated ? (int) ($payment['outstanding_cents'] ?? 0) : null;

        return [
            'client_company_id' => $id === 0 ? null : $id,
            'company_name' => $id === 0 ? null : $company?->name,
            'category' => $id === 0 ? null : ($company?->category?->value ?? 'company'),
            'state' => $state,
            'generated' => $generated,
            'nothing_payable' => $generated && $nothingPayable,
            'legacy_approved' => $generated && $section->status === PayrollRunSection::STATUS_APPROVED,
            'section_id' => $section?->id,
            'section_status' => $section?->status,
            'generated_at' => $section?->generated_at?->toIso8601String(),
            'generation_warning_count' => $generated ? count($section->generation_warnings ?? []) : 0,
            // null = not generated: shown as "-", never as zero or an estimate.
            'payroll_total_cents' => $total,
            'payments_received_cents' => $paid,
            'outstanding_cents' => $outstanding,
            'payroll_total' => $total === null ? null : PayrollMoney::format($total),
            'payments_received' => $paid === null ? null : PayrollMoney::format($paid),
            'outstanding' => $outstanding === null ? null : PayrollMoney::format($outstanding),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private static function summary(array $rows): array
    {
        $count = fn (string $state): int => count(array_filter($rows, fn (array $r): bool => $r['state'] === $state));
        $total = (int) array_sum(array_map(fn (array $r): int => (int) ($r['payroll_total_cents'] ?? 0), $rows));
        $paid = (int) array_sum(array_map(fn (array $r): int => (int) ($r['payments_received_cents'] ?? 0), $rows));
        $outstanding = (int) array_sum(array_map(fn (array $r): int => (int) ($r['outstanding_cents'] ?? 0), $rows));
        $companies = count($rows);

        return [
            'companies_assigned' => $companies,
            'companies_generated' => count(array_filter($rows, fn (array $r): bool => $r['generated'])),
            'not_generated' => $count(self::NOT_GENERATED),
            'draft' => $count(self::DRAFT),
            'reviewed' => $count(self::REVIEWED),
            'finalized_unpaid' => $count(self::FINALIZED),
            'partially_paid' => $count(self::PARTIALLY_PAID),
            'paid' => $count(self::PAID),
            'overpaid' => $count(self::OVERPAID),
            // The payroll total covers the GENERATED companies only; a company that was not generated has no total (it is counted in not_generated).
            'payroll_total_cents' => $total,
            'payments_received_cents' => $paid,
            'outstanding_cents' => $outstanding,
            'payroll_total' => PayrollMoney::format($total),
            'payments_received' => PayrollMoney::format($paid),
            'outstanding' => PayrollMoney::format($outstanding),
            // "All Companies Paid" only when there are companies and EVERY assigned company is fully paid (a not-generated company is never paid).
            'all_companies_paid' => $companies > 0 && $count(self::PAID) === $companies,
        ];
    }
}
