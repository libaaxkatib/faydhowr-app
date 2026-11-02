<?php

namespace App\Console\Commands;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\PayrollRun;
use App\Support\Hr\MonthBaseline\MonthBaselineRestorer;
use App\Support\Hr\WorkbookPlan\WorkbookPlanReader;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use RuntimeException;

/**
 * Month baseline restart (see MonthBaselineRestorer). DRY RUN unless --commit: the dry run reads the database, applies the plan inside a transaction
 * that is always rolled back, verifies it and recalculates the month's payroll with the production calculator, and prints a plan hash. A real run needs
 * --commit, --admin-email (a Super Admin), a --reason and --confirm=<that plan hash>, so it can only apply exactly what was reviewed: if anything
 * changed since the dry run the hash differs and nothing is written. The month's payroll is never approved, finalized, paid or regenerated: after the
 * restart the owner presses Generate himself. --expect-reopen/--expect-retire/--expect-keep pin the plan the owner approved: if the data now calls for a
 * different set of assignments, the run is BLOCKED and reported instead of applying an unapproved alternative.
 */
class RestoreMonthBaselineCommand extends Command
{
    protected $signature = 'hr:restore-month-baseline
        {--month= : The calendar month to restart, YYYY-MM (exactly one month)}
        {--baseline=* : Approved plan CSV(s) (source_row,employee_id,employee_number,name_key,expected_status,company,salary,start_date) - repeat for Part One and Part Two}
        {--keep-assignment=* : Assignment id(s) the owner confirmed as Excel-consistent entries; left exactly as they are}
        {--expect-reopen= : The approved assignment ids to reopen, comma separated; a different plan is blocked}
        {--expect-retire= : The approved assignment ids to retire, comma separated; a different plan is blocked}
        {--expect-keep= : The approved assignment ids to keep, comma separated; a different plan is blocked}
        {--source-label=approved Excel baseline (EMPLOYEE AND COMPANY.xlsx, Part One and Part Two) : Recorded in the notes and audit events}
        {--reason= : Why the restart is done (required with --commit, at least 10 characters)}
        {--admin-email= : Super Admin who performs the run (required with --commit)}
        {--confirm= : The plan hash printed by the dry run (required with --commit)}
        {--report= : Write one CSV row per assignment of the plan}
        {--preview= : Write the payroll line-by-line comparison (current vs proposed) to this CSV}
        {--commit : Actually write to the database (default is a dry run)}';

    protected $description = 'Restore one month\'s assignments to the approved Excel baseline and retire the entries made after it. Dry run unless --commit.';

    public function handle(WorkbookPlanReader $reader, MonthBaselineRestorer $restorer): int
    {
        $commit = (bool) $this->option('commit');
        $month = (string) $this->option('month');

        try {
            $baseline = [];

            foreach ((array) $this->option('baseline') as $path) {
                $baseline = [...$baseline, ...$reader->employees((string) $path)];
            }

            if ($baseline === []) {
                throw new InvalidArgumentException('Give at least one approved plan file with --baseline=path.csv.');
            }

            $plan = $restorer->plan($month, $baseline, array_map('intval', (array) $this->option('keep-assignment')), (string) $this->option('source-label'));
        } catch (InvalidArgumentException|RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        // The owner approved one specific set of changes. If the data now calls for a different one, that is reported and blocked, never applied.
        foreach (['reopen', 'retire', 'keep'] as $action) {
            $expected = $this->option("expect-{$action}");

            if ($expected === null || $expected === '') {
                continue;
            }

            $want = array_values(array_unique(array_map('intval', array_filter(array_map('trim', explode(',', (string) $expected)), fn (string $v): bool => $v !== ''))));
            $got = array_values(array_unique(array_map(fn (array $i): int => (int) $i['assignment_id'], array_filter($plan['items'], fn (array $i): bool => $i['action'] === $action))));
            sort($want);
            sort($got);

            if ($want !== $got) {
                $plan['blockers'][] = ['code' => 'PLAN_DIFFERS_FROM_APPROVED', 'message' => "The approved plan {$action}s assignment(s) [".implode(', ', $want).'] but the current data would '.$action.' ['.implode(', ', $got).']. Nothing is applied: report the difference to the owner.'];
            }
        }

        $this->line($commit ? '<info>COMMIT</info>' : '<comment>DRY RUN - nothing is written (the preview runs inside a transaction that is rolled back).</comment>');
        $this->printPlan($plan);

        $dry = $restorer->dryRun($plan);
        $this->printDryRun($dry);

        if ($this->option('report')) {
            $this->csv((string) $this->option('report'), array_map(fn (array $i): array => ['assignment_id' => $i['assignment_id'], 'employee_number' => $i['employee_number'], 'employee_name' => $i['employee_name'], 'company' => $i['company'], 'action' => $i['action'], 'reason' => $i['reason'], 'before' => json_encode($i['before']), 'after' => json_encode($i['after'])], $plan['items']));
        }

        if ($this->option('preview')) {
            $this->csv((string) $this->option('preview'), $dry['payroll']['lines']);
        }

        $blocked = $plan['blockers'] !== [] || ! $dry['verification']['ok'];

        if (! $commit) {
            $this->line('');
            $this->line($blocked ? '<error>BLOCKED - this plan cannot be applied (see above).</error>' : '<info>Plan hash (needed with --commit --confirm=):</info> '.$plan['plan_hash']);
            $this->comment('Dry run complete. Review it; then re-run with --commit --admin-email=... --reason=... --confirm=<plan hash> to apply exactly this plan.');

            return $blocked ? self::FAILURE : self::SUCCESS;
        }

        if ($blocked) {
            $this->error('Refusing to apply: the plan has blockers or failed verification. Nothing was written.');

            return self::FAILURE;
        }

        $actor = Admin::query()->where('email', (string) $this->option('admin-email'))->first();

        if ($actor === null || $actor->role !== AdminRole::SuperAdmin) {
            $this->error('--commit needs --admin-email of an existing Super Admin.');

            return self::FAILURE;
        }

        $reason = trim((string) $this->option('reason'));

        if (mb_strlen($reason) < 10) {
            $this->error('--commit needs --reason (at least 10 characters).');

            return self::FAILURE;
        }

        if (! hash_equals($plan['plan_hash'], (string) $this->option('confirm'))) {
            $this->error('--confirm does not match the plan hash of this run. Re-run the dry run, review it, and confirm its hash. Nothing was written.');

            return self::FAILURE;
        }

        try {
            $result = DB::transaction(function () use ($restorer, $plan, $actor, $reason, $baseline, $month): array {
                // Lock the month's payroll run first, then plan again under the lock: it must still be exactly the plan that was reviewed.
                PayrollRun::query()->where('period', $month)->lockForUpdate()->first();
                $again = $restorer->plan($month, $baseline, array_map('intval', (array) $this->option('keep-assignment')), (string) $this->option('source-label'));

                if ($again['plan_hash'] !== $plan['plan_hash']) {
                    throw new RuntimeException('The data changed after the dry run (plan hash differs). Nothing was written; run the dry run again.');
                }

                $guard = $restorer->snapshot($again);
                $batch = MonthBaselineRestorer::newBatch();
                $restorer->apply($again, $actor, true, $reason, $batch);
                $verification = $restorer->verify($again, $guard);

                if (! $verification['ok']) {
                    throw new RuntimeException('Verification failed, everything is rolled back: '.implode(' ', $verification['problems']));
                }

                // The month's payroll is left exactly as it is: the owner regenerates the draft himself afterwards.
                $statusAfter = PayrollRun::query()->where('period', $month)->value('status');
                $restorer->recordBatch($again, $actor, $reason, $batch, ['payroll_status' => $statusAfter]);

                return ['batch' => $batch, 'payroll_status' => $statusAfter];
            });
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("Applied. Batch {$result['batch']}. Payroll {$month} is ".($result['payroll_status'] ?? 'not created').' (the draft was NOT regenerated: press Generate when you are ready). Nothing was approved, finalized or paid.');

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $plan
     */
    private function printPlan(array $plan): void
    {
        $this->line("Month {$plan['month']} ({$plan['first_day']} .. {$plan['last_day']}) | baseline rows {$plan['baseline_rows']}");
        $summary = $plan['summary'];
        ksort($summary);
        $this->line('<info>Plan</info>: '.implode(', ', array_map(fn ($k, $v) => "{$k}: {$v}", array_keys($summary), $summary)));

        foreach ($plan['items'] as $item) {
            if (in_array($item['action'], ['intact', 'duplicate_row'], true)) {
                continue;
            }

            $this->line(sprintf('  %-14s #%-4s %s %s - %s', strtoupper($item['action']), $item['assignment_id'], $item['employee_number'] ?? '', $item['company'] ?? '', $item['reason']));
        }

        foreach ($plan['warnings'] as $w) {
            $this->warn("  warning {$w['code']}: {$w['message']}");
        }

        foreach ($plan['blockers'] as $b) {
            $this->error("  BLOCKER {$b['code']}: {$b['message']}");
        }
    }

    /**
     * @param  array<string, mixed>  $dry
     */
    private function printDryRun(array $dry): void
    {
        $v = $dry['verification'];
        $this->line('<info>Verification</info> (applied inside a rolled-back transaction): '.($v['ok'] ? 'all checks passed' : 'FAILED'));

        foreach ($v['problems'] as $p) {
            $this->error("  {$p}");
        }

        $p = $dry['payroll'];
        $fmt = fn (int $c): string => number_format($c / 100, 2);
        $this->line(sprintf(
            '<info>Payroll preview %s</info> (USD): current %d line(s) %s -> proposed %d line(s) %s, difference %s; %d employee(s) paid a different total; baseline salaries alone add up to %s',
            $p['month'], $p['current_lines'], $fmt($p['current_total_cents']), $p['proposed_lines'], $fmt($p['proposed_total_cents']), $fmt($p['difference_cents']), $p['employees_whose_pay_changes'], $fmt($p['expected_baseline_cents']),
        ));
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function csv(string $path, array $rows): void
    {
        $h = fopen($path, 'w');
        fwrite($h, "\xEF\xBB\xBF");

        if ($rows !== []) {
            fputcsv($h, array_keys($rows[0]), ',', '"', '\\');

            foreach ($rows as $r) {
                fputcsv($h, array_map(fn ($v) => $v === null ? '' : (string) $v, $r), ',', '"', '\\');
            }
        }

        fclose($h);
        $this->line("Wrote {$path}");
    }
}
