<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * The production scheduler (a Railway cron service runs this once an hour, UTC). It does what routes/console.php describes, without depending on a
 * process that never stops, and it is safe to run more than once:
 *
 *   00 UTC        uploads:purge-expired       expired, unattached staged uploads only
 *   02 UTC        hr:purge-import-staging     personal data of expired or cancelled import staging only
 *   08-20 UTC     follow-ups:notify-due       reminders for follow-ups due today (Mogadishu); repeats are ignored (unique event id)
 *   every run     the notification queues     notifications-in-app, notifications-email, notifications-sms - NEVER the `default` queue
 *
 * Every task is run on its own: a failure is recorded and the next task still runs, and the run ends with a non-zero status. Nothing here touches payroll,
 * employees, assignments or imported records. The summary line and `scheduler:last-run` hold numbers and exception class names only - never a name, a
 * phone number or an exception message (a database message can carry values).
 */
class RunHourlyScheduler extends Command
{
    /** The queues that are drained. `default` holds report exports whose files are written to a container-local disk, so it is left alone. */
    public const QUEUES = ['notifications-in-app', 'notifications-email', 'notifications-sms'];

    public const LAST_RUN_KEY = 'scheduler:last-run';

    public const LOCK_KEY = 'scheduler:run-hourly';

    protected $signature = 'scheduler:run-hourly
        {--dry-run : Only report what each task would do (nothing is purged, sent or processed, and nothing is recorded)}
        {--hour= : Act as this UTC hour (0-23) instead of the real one (for rehearsals and tests)}
        {--timeout=300 : Longest the whole run may last, in seconds}';

    protected $description = 'Hourly production scheduler: purges, follow-up reminders and the notification queues (see class comment)';

    /**
     * The tasks that belong to a UTC hour.
     *
     * @return list<array{name: string, command: string, window: string}>
     */
    public static function tasksFor(int $utcHour): array
    {
        $tasks = [];

        if ($utcHour === 0) {
            $tasks[] = ['name' => 'uploads', 'command' => 'uploads:purge-expired', 'window' => '00 UTC'];
        }

        if ($utcHour === 2) {
            $tasks[] = ['name' => 'import_staging', 'command' => 'hr:purge-import-staging', 'window' => '02 UTC'];
        }

        if ($utcHour >= 8 && $utcHour <= 20) {
            $tasks[] = ['name' => 'follow_ups', 'command' => 'follow-ups:notify-due', 'window' => '08-20 UTC'];
        }

        return $tasks;
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $started = CarbonImmutable::now('UTC');
        $hour = $this->option('hour');

        if ($hour !== null && (! ctype_digit((string) $hour) || (int) $hour > 23)) {
            $this->error('--hour must be a whole number from 0 to 23.');

            return self::INVALID;
        }

        $utcHour = $hour === null ? (int) $started->format('G') : (int) $hour;
        $timeout = max(1, (int) $this->option('timeout'));
        $deadline = $started->addSeconds($timeout);

        // A backstop in case something hangs: PHP stops the run (non-zero exit) shortly after the deadline, and a single database statement may not run for long.
        set_time_limit($timeout + 30);

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('SET statement_timeout = '.min($timeout, 120) * 1000);
        }

        // Two runs never overlap (Railway also skips a run while the previous one is active). A lock left behind by a crash frees itself after the timeout.
        $lock = $dryRun ? null : Cache::lock(self::LOCK_KEY, $timeout + 60);

        if ($lock !== null && ! $lock->get()) {
            $this->line(json_encode(['scheduler' => 'run-hourly', 'status' => 'skipped_overlap', 'hour_utc' => $utcHour], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        try {
            $results = [];

            foreach (self::tasksFor($utcHour) as $task) {
                $results[$task['name']] = $this->runTask($task['command'], $dryRun, $deadline);
            }

            $results['queues'] = $this->drainQueues($dryRun, $deadline);
        } finally {
            $lock?->release();
        }

        $failed = array_keys(array_filter($results, fn (array $r): bool => in_array($r['status'], ['failed', 'timeout'], true)));
        $summary = [
            'scheduler' => 'run-hourly',
            'status' => $failed === [] ? 'ok' : 'failed',
            'dry_run' => $dryRun,
            'hour_utc' => $utcHour,
            'started_at' => $started->toIso8601String(),
            'finished_at' => CarbonImmutable::now('UTC')->toIso8601String(),
            'failed_tasks' => $failed,
            'tasks' => $results,
        ];

        if (! $dryRun) {
            try {
                Cache::forever(self::LAST_RUN_KEY, json_encode($summary, JSON_THROW_ON_ERROR));
            } catch (Throwable $e) {
                $summary['last_run_recorded'] = false;
                $summary['record_error'] = class_basename($e);
                $summary['status'] = 'failed';
                $failed[] = 'last_run';
            }
        }

        $this->line(json_encode($summary, JSON_THROW_ON_ERROR));

        if ($failed !== []) {
            $this->error('Scheduler run finished with failed task(s): '.implode(', ', $failed));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /** @return array<string, mixed> */
    private function runTask(string $command, bool $dryRun, CarbonImmutable $deadline): array
    {
        if (CarbonImmutable::now('UTC')->greaterThanOrEqualTo($deadline)) {
            return ['status' => 'timeout', 'command' => $command, 'note' => 'The run ran out of time before this task.'];
        }

        $began = microtime(true);

        try {
            $code = Artisan::call($command, $dryRun ? ['--dry-run' => true] : []);
            $output = trim(preg_replace('/\s+/', ' ', Artisan::output()) ?? '');

            return [
                'status' => $code === 0 ? 'ok' : 'failed',
                'command' => $command,
                'exit_code' => $code,
                // The commands only print counts; the length is capped anyway.
                'result' => mb_substr($output, 0, 200),
                'seconds' => round(microtime(true) - $began, 2),
            ];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'command' => $command, 'error' => $this->safeError($e), 'seconds' => round(microtime(true) - $began, 2)];
        }
    }

    /** @return array<string, mixed> */
    private function drainQueues(bool $dryRun, CarbonImmutable $deadline): array
    {
        $connection = (string) config('queue.default');

        if ($connection === 'sync' || $connection === 'null') {
            return ['status' => 'skipped', 'note' => "The queue connection is '{$connection}': there is nothing to drain."];
        }

        $remaining = (int) max(0, CarbonImmutable::now('UTC')->diffInSeconds($deadline, false));

        try {
            $before = $this->queueState($connection);

            if ($dryRun) {
                return ['status' => 'ok', 'dry_run' => true, 'connection' => $connection, 'pending' => $before['pending'], 'default_pending' => $before['default'], 'failed_jobs' => $before['failed']];
            }

            if ($remaining < 5) {
                return ['status' => 'timeout', 'note' => 'The run ran out of time before the queues were drained.', 'pending' => $before['pending']];
            }

            // Only the notification queues. The worker stops when they are empty, or when the time left for this run is used (the job in hand is finished first).
            $code = Artisan::call('queue:work', [
                'connection' => $connection,
                '--queue' => implode(',', self::QUEUES),
                '--stop-when-empty' => true,
                '--max-time' => min($remaining - 2, 240),
                '--max-jobs' => 500,
                '--tries' => 3,
                '--timeout' => 60,
                // The worker's default (128 MB) is below what a long-lived process can legitimately hold; a runaway is stopped by PHP's own limit.
                '--memory' => 1024,
            ]);

            $after = $this->queueState($connection);
            $newlyFailed = max(0, $after['failed'] - $before['failed']);

            return [
                'status' => $code === 0 && $newlyFailed === 0 ? 'ok' : 'failed',
                'connection' => $connection,
                'exit_code' => $code,
                'pending_before' => $before['pending'],
                'pending_after' => $after['pending'],
                'newly_failed_jobs' => $newlyFailed,
                'failed_jobs_total' => $after['failed'],
                'default_pending_before' => $before['default'],
                'default_pending_after' => $after['default'],
            ];
        } catch (Throwable $e) {
            return ['status' => 'failed', 'error' => $this->safeError($e)];
        }
    }

    /** @return array{pending: array<string, int>, default: int, failed: int} */
    private function queueState(string $connection): array
    {
        $pending = [];

        foreach (self::QUEUES as $queue) {
            $pending[$queue] = (int) Queue::connection($connection)->size($queue);
        }

        return [
            'pending' => $pending,
            'default' => (int) Queue::connection($connection)->size('default'),
            'failed' => Schema::hasTable('failed_jobs') ? (int) DB::table('failed_jobs')->count() : 0,
        ];
    }

    /** The class (and the SQLSTATE of a database error) only: a message can contain the values of a query. */
    private function safeError(Throwable $e): string
    {
        $state = $e instanceof QueryException ? ' SQLSTATE '.($e->errorInfo[0] ?? '?') : '';

        return class_basename($e).$state;
    }
}
