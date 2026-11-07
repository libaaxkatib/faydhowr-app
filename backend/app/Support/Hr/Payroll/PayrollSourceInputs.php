<?php

namespace App\Support\Hr\Payroll;

use App\Models\PayrollRun;
use App\Models\PayrollRunSection;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\DB;

/**
 * The freshness guard of a company's payroll draft for its SOURCE DATA (owner approval, 2026-11-05): the assignments, the salary rows and the Temporary
 * covers the lines were calculated from. (Penalties and advances have their own guard: DeductionInputsFingerprint.)
 *
 * Payroll lines are a calculation made at one moment. If someone ends an assignment, adds a salary change or approves a Temporary cover afterwards, the
 * stored lines no longer describe the company and reviewing or finalizing them would lock the old numbers in (this is exactly how a finalized and paid
 * company once kept a wrong split between two employees). Generate therefore stores a compact snapshot of those inputs on the section; Review and
 * Finalize compare it with the data as it is NOW and refuse a draft that went stale, naming each change, until that company is generated again.
 *
 * What is covered, for the assignments that overlap the month and belong to THIS company:
 *  - assignment: type, status, start and end date, which assignment it replaces, which company it belongs to; one added, removed (cancelled, moved to another
 *    company, no longer overlapping the month) or changed;
 *  - salary: the salary rows of those assignments that take effect by the end of the month (amount, currency, frequency, effective date);
 *  - Temporary cover: its employee, dates, status and whether its cost is approved;
 *  - absence window of the holder of an assignment (replacement cycles).
 * What is deliberately NOT covered, so valid work is never blocked: notes, position, shift, a workplace of the same company, attendance and leave marks,
 * other companies' data, rows that only take effect after the month, and everything on a section that is finalized, approved or has no snapshot (a
 * section generated before this release is judged as before; nothing existing is rewritten).
 */
final class PayrollSourceInputs
{
    public const VERSION = 1;

    /** Most changes listed in an answer; the real count is always reported next to them. */
    public const MAX_LISTED = 50;

    /** @return array<string, mixed> */
    public static function snapshot(string $period, ?int $companyId): array
    {
        $first = $period.'-01';
        $last = CarbonImmutable::parse($first)->endOfMonth()->toDateString();

        $assignmentRows = DB::table('employee_work_assignments as a')
            ->leftJoin('work_locations as l', 'l.id', '=', 'a.work_location_id')
            ->join('employees as e', 'e.id', '=', 'a.employee_id')
            ->whereDate('a.start_date', '<=', $last)
            ->where(fn ($q) => $q->whereNull('a.end_date')->orWhereDate('a.end_date', '>=', $first))
            ->when($companyId === null,
                fn ($q) => $q->whereRaw('COALESCE(a.client_company_id, l.client_company_id) IS NULL'),
                fn ($q) => $q->whereRaw('COALESCE(a.client_company_id, l.client_company_id) = ?', [$companyId]))
            ->orderBy('a.id')
            ->get(['a.id', 'e.employee_number', 'a.assignment_type', 'a.status', 'a.start_date', 'a.end_date', 'a.replaces_assignment_id']);

        $assignments = [];

        foreach ($assignmentRows as $r) {
            $assignments[(string) $r->id] = [
                'emp' => $r->employee_number,
                'type' => self::text($r->assignment_type),
                'status' => self::text($r->status),
                'start' => self::day($r->start_date),
                'end' => self::day($r->end_date),
                'replaces' => $r->replaces_assignment_id === null ? null : (int) $r->replaces_assignment_id,
            ];
        }

        $ids = array_map('intval', array_keys($assignments));
        $salaries = [];
        $covers = [];
        $absences = [];

        foreach (array_chunk($ids, 500) as $chunk) {
            foreach (DB::table('assignment_salary_histories')->whereIn('work_assignment_id', $chunk)->whereDate('effective_from', '<=', $last)->orderBy('work_assignment_id')->orderBy('effective_from')->get(['work_assignment_id', 'effective_from', 'amount', 'currency', 'frequency']) as $r) {
                $salaries[$r->work_assignment_id.'|'.self::day($r->effective_from)] = ['a' => (int) $r->work_assignment_id, 'from' => self::day($r->effective_from), 'amount' => number_format((float) $r->amount, 2, '.', ''), 'currency' => $r->currency, 'frequency' => self::text($r->frequency)];
            }

            foreach (DB::table('temporary_replacements as t')->leftJoin('employees as e', 'e.id', '=', 't.replacement_employee_id')
                ->whereIn('t.work_assignment_id', $chunk)->whereDate('t.start_date', '<=', $last)
                ->where(fn ($q) => $q->whereNull('t.end_date')->orWhereDate('t.end_date', '>=', $first))
                ->orderBy('t.id')->get(['t.id', 't.work_assignment_id', 'e.employee_number', 't.start_date', 't.end_date', 't.planned_end_date', 't.status', 't.approved_at']) as $r) {
                $covers[(string) $r->id] = ['a' => (int) $r->work_assignment_id, 'by' => $r->employee_number, 'start' => self::day($r->start_date), 'end' => self::day($r->end_date), 'planned_end' => self::day($r->planned_end_date), 'status' => self::text($r->status), 'approved' => $r->approved_at !== null];
            }

            foreach (DB::table('assignment_absences')->whereIn('work_assignment_id', $chunk)->whereDate('absent_from', '<=', $last)
                ->where(fn ($q) => $q->whereNull('returned_on')->orWhereDate('returned_on', '>=', $first))
                ->orderBy('id')->get(['id', 'work_assignment_id', 'last_working_day', 'absent_from', 'returned_on', 'terminated_on']) as $r) {
                $absences[(string) $r->id] = ['a' => (int) $r->work_assignment_id, 'last_working_day' => self::day($r->last_working_day), 'absent_from' => self::day($r->absent_from), 'returned_on' => self::day($r->returned_on), 'terminated_on' => self::day($r->terminated_on)];
            }
        }

        ksort($salaries);

        return ['v' => self::VERSION, 'period' => $period, 'assignments' => $assignments, 'salaries' => $salaries, 'covers' => $covers, 'absences' => $absences];
    }

    /** True when Generate stored a snapshot of this version for this period; a section built before the guard existed (or by an older version) has none. */
    public static function hasSnapshot(PayrollRunSection $section, string $period): bool
    {
        $snapshot = $section->generation_inputs;

        return is_array($snapshot) && ($snapshot['v'] ?? null) === self::VERSION && ($snapshot['period'] ?? null) === $period;
    }

    /**
     * Owner decision O2 (2026-11-07): a company cannot be reviewed or finalized without a valid stored snapshot, because without one the freshness
     * guard cannot tell whether the lines still match the data. Generate / refresh draft stores the snapshot. Nothing is changed when this refuses.
     */
    public static function assertSnapshotted(PayrollRunSection $section, PayrollRun $run): void
    {
        if (self::hasSnapshot($section, $run->period)) {
            return;
        }

        $name = $section->clientCompany?->name ?? 'this company';

        throw new HttpResponseException(ApiResponse::error(
            "Payroll {$run->period} for {$name} has no valid stored snapshot of the data it was calculated from (it was built before snapshot protection or by an older version), so it cannot be reviewed or finalized as it is. Nothing was changed. Press \"Generate / refresh draft\" for {$name}, check the lines, then review it again.",
            'PAYROLL_SNAPSHOT_MISSING',
            409,
            ['company_id' => $section->client_company_id, 'period' => $run->period, 'required_action' => 'generate'],
        ));
    }

    /**
     * What changed in the source data since this DRAFT section was generated, one entry per change. Empty when nothing changed, when the section is
     * locked (finalized / approved), or when it has no snapshot (generated before this release; Review and Finalize now refuse such a section: see assertSnapshotted).
     *
     * @return list<array<string, mixed>>
     */
    public static function changes(PayrollRunSection $section, string $period): array
    {
        if ($section->isLocked() || ! is_array($section->generation_inputs) || ($section->generation_inputs['v'] ?? null) !== self::VERSION) {
            return [];
        }

        return self::diff($section->generation_inputs, self::snapshot($period, $section->client_company_id));
    }

    /**
     * @param  array<string, mixed>  $then  the snapshot stored at Generate
     * @param  array<string, mixed>  $now  the snapshot of the data as it is
     * @return list<array<string, mixed>>
     */
    public static function diff(array $then, array $now): array
    {
        $out = [];
        $employeeOf = function (int $assignmentId) use ($then, $now): ?string {
            return $now['assignments'][(string) $assignmentId]['emp'] ?? $then['assignments'][(string) $assignmentId]['emp'] ?? null;
        };

        foreach (self::compare($then['assignments'] ?? [], $now['assignments'] ?? [], ['type', 'status', 'start', 'end', 'replaces']) as [$key, $change, $fields]) {
            $row = $now['assignments'][$key] ?? $then['assignments'][$key];
            $out[] = ['category' => 'assignment', 'change' => $change, 'id' => (int) $key, 'assignment_id' => (int) $key, 'employee_number' => $row['emp'] ?? null, 'detail' => null, 'fields' => $fields];
        }

        foreach (self::compare($then['salaries'] ?? [], $now['salaries'] ?? [], ['amount', 'currency', 'frequency']) as [$key, $change, $fields]) {
            $row = $now['salaries'][$key] ?? $then['salaries'][$key];
            $out[] = ['category' => 'salary', 'change' => $change, 'id' => null, 'assignment_id' => $row['a'], 'employee_number' => $employeeOf($row['a']), 'detail' => $row['from'], 'fields' => $fields];
        }

        foreach (self::compare($then['covers'] ?? [], $now['covers'] ?? [], ['by', 'start', 'end', 'planned_end', 'status', 'approved']) as [$key, $change, $fields]) {
            $row = $now['covers'][$key] ?? $then['covers'][$key];
            $out[] = ['category' => 'replacement', 'change' => $change, 'id' => (int) $key, 'assignment_id' => $row['a'], 'employee_number' => $employeeOf($row['a']), 'detail' => $row['by'], 'fields' => $fields];
        }

        foreach (self::compare($then['absences'] ?? [], $now['absences'] ?? [], ['last_working_day', 'absent_from', 'returned_on', 'terminated_on']) as [$key, $change, $fields]) {
            $row = $now['absences'][$key] ?? $then['absences'][$key];
            $out[] = ['category' => 'absence', 'change' => $change, 'id' => (int) $key, 'assignment_id' => $row['a'], 'employee_number' => $employeeOf($row['a']), 'detail' => null, 'fields' => $fields];
        }

        return $out;
    }

    /**
     * @param  array<string, array<string, mixed>>  $then
     * @param  array<string, array<string, mixed>>  $now
     * @param  list<string>  $fields
     * @return list<array{0: string, 1: string, 2: list<array{field: string, before: ?string, after: ?string}>}>
     */
    private static function compare(array $then, array $now, array $fields): array
    {
        $out = [];
        $keys = array_values(array_unique([...array_keys($then), ...array_keys($now)]));
        sort($keys, SORT_NATURAL);

        foreach ($keys as $key) {
            $key = (string) $key;

            if (! isset($then[$key])) {
                $out[] = [$key, 'added', array_map(fn (string $f): array => ['field' => $f, 'before' => null, 'after' => self::show($now[$key][$f] ?? null)], $fields)];

                continue;
            }

            if (! isset($now[$key])) {
                $out[] = [$key, 'removed', array_map(fn (string $f): array => ['field' => $f, 'before' => self::show($then[$key][$f] ?? null), 'after' => null], $fields)];

                continue;
            }

            $changed = [];

            foreach ($fields as $f) {
                if (($then[$key][$f] ?? null) !== ($now[$key][$f] ?? null)) {
                    $changed[] = ['field' => $f, 'before' => self::show($then[$key][$f] ?? null), 'after' => self::show($now[$key][$f] ?? null)];
                }
            }

            if ($changed !== []) {
                $out[] = [$key, 'changed', $changed];
            }
        }

        return $out;
    }

    /** A plain-English one-line summary, for the error message and the audit trail (the screen shows each change in the user's language). @param list<array<string, mixed>> $changes */
    public static function summary(array $changes): string
    {
        $count = fn (string $c): int => count(array_filter($changes, fn (array $x): bool => $x['category'] === $c));
        $parts = [];

        foreach (['assignment' => 'assignment', 'salary' => 'salary', 'replacement' => 'Temporary Replacement', 'absence' => 'absence'] as $key => $label) {
            if ($count($key) > 0) {
                $parts[] = $count($key).' '.$label.' change(s)';
            }
        }

        return implode(', ', $parts);
    }

    /** @throws HttpResponseException 409 when the source data changed after this company was generated */
    public static function assertFresh(PayrollRunSection $section, PayrollRun $run): void
    {
        $changes = self::changes($section, $run->period);

        if ($changes === []) {
            return;
        }

        $name = $section->clientCompany?->name ?? 'this company';

        throw new HttpResponseException(ApiResponse::error(
            "The source data of {$name} changed after payroll {$run->period} was generated (".self::summary($changes).'), so the lines no longer match. Nothing was changed. Generate '.$name.' again, then review and finalize it.',
            'PAYROLL_SOURCES_CHANGED',
            409,
            ['changes' => array_slice($changes, 0, self::MAX_LISTED), 'total' => count($changes)],
        ));
    }

    private static function day(mixed $value): ?string
    {
        return $value === null || $value === '' ? null : substr((string) $value, 0, 10);
    }

    private static function text(mixed $value): ?string
    {
        return $value === null ? null : (is_object($value) && property_exists($value, 'value') ? (string) $value->value : (string) $value);
    }

    private static function show(mixed $value): ?string
    {
        return match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'yes' : 'no',
            default => (string) $value,
        };
    }
}
