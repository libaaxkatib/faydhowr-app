<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Support\Hr\Payroll\PaymentNumber;
use App\Support\Hr\WorkbookPlan\WorkbookPlanExecutor;
use App\Support\Hr\WorkbookPlan\WorkbookPlanReader;
use Illuminate\Console\Command;
use InvalidArgumentException;

/**
 * Applies the approved company-workbook plan. DRY RUN unless --commit: a dry
 * run reads the database and reports exactly what would happen for every
 * record, end to end, without writing anything. A real run needs --commit and
 * --admin-email (a Super Admin, so every audit event names a real person).
 * Steps can be run separately (--step=companies|status|assignments) and the
 * command is safe to repeat.
 */
class ApplyWorkbookPlanCommand extends Command
{
    protected $signature = 'hr:apply-workbook-plan
        {--companies= : CSV of companies to register (name,category,aliases)}
        {--employees= : CSV of approved employees (source_row,employee_id,employee_number,name_key,expected_status,company,salary,start_date)}
        {--step=* : companies, status, assignments and/or payments (default: companies, status and assignments for the files given; payments only when asked for)}
        {--note=Approved as working in the company workbook (EMPLOYEE AND COMPANY.xlsx) : Status-history note for the Active transition}
        {--source-label=EMPLOYEE AND COMPANY.xlsx : Name of the approved workbook, recorded in status history, assignment notes and company aliases}
        {--joining-date= : Set employees.joining_date (only where empty), e.g. 2026-10-01}
        {--override-reason= : Super Admin reason, required to move employees to Active when that is not a normal lifecycle step (for example Applicant to Active)}
        {--admin-email= : Super Admin who performs the run (required with --commit)}
        {--payment-source=part_one : Which workbook the payment numbers come from (part_one or part_two); recorded as their source}
        {--replace-stored : With --step=payments: replace a DIFFERENT payment number that is already stored with the Excel value (default: report a conflict and keep it)}
        {--report= : Write every record result to this CSV path}
        {--commit : Actually write to the database (default is a dry run)}';

    protected $description = 'Register companies, activate approved employees and assign them to companies from the approved workbook plan. Dry run unless --commit.';

    public function handle(WorkbookPlanReader $reader, WorkbookPlanExecutor $executor): int
    {
        $commit = (bool) $this->option('commit');
        $dryRun = ! $commit;
        $steps = $this->option('step') ?: array_values(array_filter([
            $this->option('companies') ? 'companies' : null,
            $this->option('employees') ? 'status' : null,
            $this->option('employees') ? 'assignments' : null,
        ]));

        $actor = null;

        if ($commit) {
            $actor = Admin::query()->where('email', (string) $this->option('admin-email'))->first();

            if ($actor === null || $actor->role !== AdminRole::SuperAdmin) {
                $this->error('--commit needs --admin-email of an existing Super Admin.');

                return self::FAILURE;
            }
        }

        try {
            $companies = in_array('companies', $steps, true) ? $reader->companies((string) $this->option('companies')) : [];
            $employees = array_intersect(['status', 'assignments', 'payments'], $steps) !== [] ? $reader->employees((string) $this->option('employees')) : [];
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line($dryRun ? '<comment>DRY RUN - nothing will be written.</comment>' : '<info>COMMIT - writing as '.$actor->email.'</info>');

        $all = [];
        $assumeCompanies = [];
        $assumeActive = [];

        if ($companies !== []) {
            $results = $executor->companies($companies, $dryRun, $actor, (string) $this->option('source-label'));
            $this->summarize('companies', $results);
            $all = array_merge($all, array_map(fn ($r) => ['step' => 'companies', ...$r], $results));
            $assumeCompanies = array_map(fn (array $c): string => mb_strtolower(trim($c['name'])), $companies);
        }

        if (in_array('status', $steps, true)) {
            $run = $executor->status($employees, (string) $this->option('note'), $this->option('joining-date') ?: null, $dryRun, $actor, $this->option('override-reason') ?: null, (string) $this->option('source-label'));
            $this->summarize('status', $run['results']);
            $all = array_merge($all, array_map(fn ($r) => ['step' => 'status', ...$r], $run['results']));
            $assumeActive = array_map(fn ($r) => (int) $r['employee_id'], array_filter($run['results'], fn ($r) => in_array($r['outcome'], ['would_activate', 'would_activate_with_override', 'already_active', 'activated'], true)));
        }

        if (in_array('assignments', $steps, true)) {
            $results = $executor->assignments($employees, $dryRun, $actor, $assumeActive, $assumeCompanies, (string) $this->option('source-label'));
            $this->summarize('assignments', $results);
            $all = array_merge($all, array_map(fn ($r) => ['step' => 'assignments', ...$r], $results));
        }

        if (in_array('payments', $steps, true)) {
            $sourceCode = match ((string) $this->option('payment-source')) {
                'part_two' => PaymentNumber::SOURCE_WORKBOOK_PART_TWO,
                'part_one' => PaymentNumber::SOURCE_WORKBOOK_PART_ONE,
                default => null,
            };

            if ($sourceCode === null) {
                $this->error('--payment-source must be part_one or part_two.');

                return self::FAILURE;
            }

            $results = $executor->paymentNumbers($employees, $dryRun, $actor, (string) $this->option('source-label'), $sourceCode, (bool) $this->option('replace-stored'));
            $this->summarize('payments', $results);
            $all = array_merge($all, array_map(fn ($r) => ['step' => 'payments', ...$r], $results));
        }

        $failed = count(array_filter($all, fn ($r) => $r['outcome'] === 'failed'));

        if ($this->option('report')) {
            $this->writeReport((string) $this->option('report'), $all);
        }

        if ($failed > 0) {
            $this->warn("{$failed} record(s) failed or were skipped with a reason - see above".($this->option('report') ? ' and the report.' : '. Use --report=path.csv for the full list.'));
        }

        if ($dryRun) {
            $this->comment('Dry run complete. Review it, then re-run with --commit --admin-email=... to apply.');
        }

        return $failed > 0 && $commit ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @param  list<array<string, mixed>>  $results
     */
    private function summarize(string $step, array $results): void
    {
        $counts = array_count_values(array_column($results, 'outcome'));
        ksort($counts);
        $this->line(sprintf('<info>%s</info>: %d record(s) - %s', strtoupper($step), count($results), implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($counts), $counts)) ?: 'none'));

        $reasons = [];

        foreach ($results as $r) {
            if ($r['outcome'] === 'failed') {
                $reasons[$r['code'] ?? 'FAILED'] = ($reasons[$r['code'] ?? 'FAILED'] ?? 0) + 1;
            }
        }

        foreach ($reasons as $code => $n) {
            $this->line("    failed - {$code}: {$n}");
        }
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function writeReport(string $path, array $rows): void
    {
        $handle = fopen($path, 'w');
        // The payments step adds its dry-run columns (source row/file, employee, original Excel value, register phone, match, stored value,
        // proposed number, whether the phone fallback would apply). Cells are written as text: a value starting with "0" keeps it.
        fputcsv($handle, ['step', 'reference', 'outcome', 'code', 'message', 'employee_number', 'excel_value', 'register_phone', 'register_check', 'stored_value', 'proposed_number', 'fallback_needed', 'source_ref'], ',', '"', '\\');

        foreach ($rows as $r) {
            $reference = $r['company'] ?? ('employee '.($r['employee_id'] ?? '?').(isset($r['source_row']) ? ' / row '.$r['source_row'] : ''));
            $isPayment = ($r['step'] ?? '') === 'payments';
            fputcsv($handle, [
                $r['step'], $reference, $r['outcome'], $r['code'] ?? '', $r['message'] ?? '',
                $isPayment ? ($r['employee_number'] ?? '') : '',
                $isPayment ? ($r['excel_value'] ?? '') : '',
                $isPayment ? ($r['register_phone'] ?? '') : '',
                $isPayment ? ($r['register_check'] ?? '') : '',
                $isPayment ? ($r['stored_value'] ?? '') : '',
                $isPayment ? ($r['proposed_number'] ?? '') : '',
                $isPayment ? (($r['fallback_needed'] ?? false) ? 'yes' : 'no') : '',
                $isPayment ? ($r['source_ref'] ?? '') : '',
            ], ',', '"', '\\');
        }

        fclose($handle);
        $this->line("Report written: {$path}");
    }
}
