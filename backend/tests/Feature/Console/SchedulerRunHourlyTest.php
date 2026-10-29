<?php

namespace Tests\Feature\Console;

use App\Console\Commands\RunHourlyScheduler;
use App\Contracts\Upload\Services\UploadServiceInterface;
use App\Jobs\Notification\ProcessNotificationJob;
use App\Jobs\Reports\GenerateReportExportJob;
use App\Models\Admin;
use App\Models\CustomerProfile;
use App\Models\Employee;
use App\Models\FollowUp;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Models\MarketingRecord;
use App\Models\Notification;
use App\Models\Upload;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Console\Output\BufferedOutput;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * `scheduler:run-hourly` (docs: RunHourlyScheduler): the production scheduler. It purges only what is expired and unattached or past its retention, sends each
 * follow-up reminder once, drains ONLY the notification queues, reports every task even when one fails, and never touches employees, assignments, payroll or
 * imported records. Synthetic data only.
 */
class SchedulerRunHourlyTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    private Admin $admin;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        // Production keeps its queue in the database; the test suite normally runs jobs inline.
        config(['queue.default' => 'database']);
        CarbonImmutable::setTestNow('2026-11-10 08:05:00 UTC');
        [$this->admin, $this->token] = $this->importSuperAdmin();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{int, array<string, mixed>} exit code and the recorded summary (null summary when none) */
    private function run_(int $hour, array $options = []): array
    {
        $code = Artisan::call('scheduler:run-hourly', ['--hour' => $hour, ...$options]);
        $last = Cache::get(RunHourlyScheduler::LAST_RUN_KEY);

        return [$code, $last === null ? [] : json_decode($last, true, 512, JSON_THROW_ON_ERROR)];
    }

    /** A follow-up due on $date, assigned to the Super Admin. */
    private function followUp(string $date, string $status = 'scheduled'): FollowUp
    {
        $id = $this->as($this->token)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Demo Facility '.$date])->assertCreated()->json('data.id');

        return FollowUp::query()->create(['marketing_record_id' => MarketingRecord::query()->findOrFail($id)->id, 'follow_up_date' => $date, 'status' => $status, 'assigned_admin_id' => $this->admin->id]);
    }

    /** @return array<string, mixed> a fingerprint of everything the scheduler must never change */
    private function businessFingerprint(): array
    {
        $tables = ['employees', 'employee_work_assignments', 'assignment_salary_histories', 'payroll_runs', 'payroll_items', 'client_companies', 'temporary_replacements', 'import_batches'];
        $result = [];

        foreach ($tables as $table) {
            $result[$table] = md5((string) json_encode(DB::table($table)->orderBy('id')->get()->map(fn ($r): array => collect((array) $r)->except(['updated_at', 'pii_purged_at', 'status'])->all())->all()));
        }

        return $result;
    }

    // ---------------------------------------------------------------------------------------------------------- the plan of the day

    public function test_each_hour_has_exactly_the_approved_tasks(): void
    {
        $byHour = [];

        foreach (range(0, 23) as $hour) {
            $byHour[$hour] = array_column(RunHourlyScheduler::tasksFor($hour), 'command');
        }

        $this->assertSame(['uploads:purge-expired'], $byHour[0]);
        $this->assertSame(['hr:purge-import-staging'], $byHour[2]);
        $this->assertSame([], $byHour[1]);
        $this->assertSame([], $byHour[7]);
        foreach (range(8, 20) as $hour) {
            $this->assertSame(['follow-ups:notify-due'], $byHour[$hour], "hour $hour");
        }
        foreach ([21, 22, 23] as $hour) {
            $this->assertSame([], $byHour[$hour], "hour $hour");
        }
    }

    public function test_the_commands_in_the_plan_are_the_commands_in_routes_console_so_the_two_cannot_drift(): void
    {
        $scheduled = collect(app(Schedule::class)->events())->map(fn ($e): string => trim((string) preg_replace('/^.*artisan[\'"]?\s+/', '', (string) $e->command)))->sort()->values()->all();
        $planned = collect(range(0, 23))->flatMap(fn (int $h): array => array_column(RunHourlyScheduler::tasksFor($h), 'command'))->unique()->sort()->values()->all();

        $this->assertSame($planned, $scheduled);
        $this->assertTrue(collect(app(Schedule::class)->events())->every(fn ($e): bool => $e->withoutOverlapping), 'the development schedule does not overlap itself either');
    }

    public function test_a_bad_hour_is_refused(): void
    {
        $this->assertNotSame(0, Artisan::call('scheduler:run-hourly', ['--hour' => 24]));
        $this->assertNotSame(0, Artisan::call('scheduler:run-hourly', ['--hour' => 'x']));
    }

    // ---------------------------------------------------------------------------------------------------------- uploads (00 UTC)

    public function test_the_midnight_run_removes_only_expired_unattached_uploads_and_nothing_else(): void
    {
        Storage::fake('local');
        $profile = CustomerProfile::factory()->for(User::factory())->create();

        Storage::disk('local')->put('uploads/expired.jpg', 'stale');
        $expired = Upload::factory()->for($profile)->expired()->create(['path' => 'uploads/expired.jpg']);
        Storage::disk('local')->put('uploads/fresh.jpg', 'fresh');
        $fresh = Upload::factory()->for($profile)->create(['path' => 'uploads/fresh.jpg']);
        $attachedButOld = Upload::factory()->for($profile)->attached()->create(['expires_at' => now()->subDays(3)]);

        $employee = $this->makeEmployee('active', 'permanent');
        $assignment = $this->makeAssignment($employee, $this->makeCompany('Demo Co'), null, 300, '2026-01-01');
        $before = $this->businessFingerprint();

        [$code, $last] = $this->run_(0);

        $this->assertSame(0, $code);
        $this->assertDatabaseMissing('uploads', ['id' => $expired->id]);
        Storage::disk('local')->assertMissing('uploads/expired.jpg');
        $this->assertDatabaseHas('uploads', ['id' => $fresh->id]);
        Storage::disk('local')->assertExists('uploads/fresh.jpg');
        $this->assertDatabaseHas('uploads', ['id' => $attachedButOld->id]); // an attached upload is never removed
        $this->assertSame($before, $this->businessFingerprint(), 'employees, assignments, payroll and imports are untouched');
        $this->assertSame('ok', $last['status']);
        $this->assertSame('ok', $last['tasks']['uploads']['status']);
        $this->assertStringContainsString('Removed 1 expired upload(s).', $last['tasks']['uploads']['result']);
        $this->assertSame($employee->id, $assignment->employee_id);
    }

    // ---------------------------------------------------------------------------------------------------------- import staging (02 UTC)

    private function stagedBatch(string $suffix): int
    {
        $this->makeEmployee('applicant', 'permanent', '61555'.str_pad($suffix, 4, '0', STR_PAD_LEFT))->update(['full_name' => "Scheduler Person {$suffix}"]);

        return $this->stageImport($this->token, 'waiting_list', $this->csvUpload(['full_name', 'phone'], [["Scheduler Person {$suffix}", '61555'.str_pad($suffix, 4, '0', STR_PAD_LEFT)], ['Nobody Known', '619990000'.$suffix]]));
    }

    public function test_the_import_run_clears_only_staging_data_that_is_due_and_leaves_registered_people_alone(): void
    {
        $due = $this->stagedBatch('1');
        $notDue = $this->stagedBatch('2');
        ImportBatch::query()->whereKey($due)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        $people = Employee::query()->orderBy('id')->get(['id', 'full_name', 'phone', 'status', 'employment_type'])->toArray();
        $personalRows = fn (int $id): int => ImportRow::query()->where('import_batch_id', $id)->whereNotNull('payload')->count();
        $this->assertSame(2, $personalRows($due));

        [$code, $last] = $this->run_(2);

        $this->assertSame(0, $code);
        $this->assertSame(0, $personalRows($due), 'the staging personal data of the due batch is gone');
        $this->assertNotNull(ImportBatch::query()->find($due)->pii_purged_at);
        $this->assertSame(2, $personalRows($notDue), 'a batch that is not due is untouched');
        $this->assertNull(ImportBatch::query()->find($notDue)->pii_purged_at);
        $this->assertSame($people, Employee::query()->orderBy('id')->get(['id', 'full_name', 'phone', 'status', 'employment_type'])->toArray(), 'registered people are never changed');
        $this->assertSame('ok', $last['tasks']['import_staging']['status']);
    }

    public function test_running_the_import_purge_again_changes_nothing(): void
    {
        $due = $this->stagedBatch('1');
        ImportBatch::query()->whereKey($due)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        $this->run_(2);
        $stamp = ImportBatch::query()->find($due)->pii_purged_at->toDateTimeString();
        CarbonImmutable::setTestNow('2026-11-10 09:30:00 UTC');
        [, $second] = $this->run_(2);

        $this->assertSame($stamp, ImportBatch::query()->find($due)->pii_purged_at->toDateTimeString());
        $this->assertStringContainsString('Purged personal data from 0 import batch(es).', $second['tasks']['import_staging']['result']);
    }

    // ---------------------------------------------------------------------------------------------------------- follow-up reminders (08-20 UTC)

    public function test_a_follow_up_due_today_is_reminded_once_even_when_the_run_repeats(): void
    {
        $due = $this->followUp('2026-11-10');
        $this->followUp('2026-11-11');
        $this->followUp('2026-11-10', 'completed');

        [$code] = $this->run_(8);
        $this->assertSame(0, $code);
        $this->assertSame(1, Notification::query()->where('event_id', 'follow-up-due-'.$due->id.'-2026-11-10')->count());

        $this->run_(9);
        $this->run_(8);
        $this->assertSame(1, Notification::query()->count(), 'a repeated run sends nothing new');
        $this->assertSame(1, Notification::query()->where('event_id', 'like', 'follow-up-due-%')->count());
    }

    public function test_the_reminder_is_delivered_in_the_same_run_through_the_in_app_queue(): void
    {
        $this->followUp('2026-11-10');

        [, $last] = $this->run_(8);

        $notification = Notification::query()->firstOrFail();
        $this->assertSame('delivered', $notification->status->value);
        $this->assertSame(0, $last['tasks']['queues']['pending_after']['notifications-in-app']);
        $this->assertSame(0, DB::table('jobs')->where('queue', 'notifications-in-app')->count());
    }

    public function test_today_is_the_mogadishu_day_not_the_utc_day(): void
    {
        // 22:30 UTC on 10 November is 01:30 on 11 November in Mogadishu.
        CarbonImmutable::setTestNow('2026-11-10 22:30:00 UTC');
        $utcToday = $this->followUp('2026-11-10');
        $mogadishuToday = $this->followUp('2026-11-11');

        $this->run_(8);

        $this->assertSame(0, Notification::query()->where('event_id', 'follow-up-due-'.$utcToday->id.'-2026-11-10')->count());
        $this->assertSame(1, Notification::query()->where('event_id', 'follow-up-due-'.$mogadishuToday->id.'-2026-11-11')->count());
    }

    public function test_follow_ups_are_only_reminded_between_08_and_20_utc(): void
    {
        $this->followUp('2026-11-10');

        foreach ([0, 2, 3, 7, 21, 23] as $hour) {
            $this->run_($hour);
        }
        $this->assertSame(0, Notification::query()->count());

        foreach ([8, 14, 20] as $hour) {
            $this->run_($hour);
        }
        $this->assertSame(1, Notification::query()->count());
    }

    // ---------------------------------------------------------------------------------------------------------- the queues

    public function test_only_the_notification_queues_are_drained_and_the_default_queue_is_never_touched(): void
    {
        $inApp = Notification::factory()->forAdmin($this->admin)->create();
        ProcessNotificationJob::dispatch($inApp->id)->onQueue('notifications-in-app');
        GenerateReportExportJob::dispatch(999);
        $this->assertSame(1, DB::table('jobs')->where('queue', 'default')->count());
        $reportJob = DB::table('jobs')->where('queue', 'default')->first();

        [$code, $last] = $this->run_(5);

        $this->assertSame(0, $code);
        $this->assertSame('delivered', $inApp->fresh()->status->value, 'the notification job ran');
        $this->assertSame(0, DB::table('jobs')->where('queue', 'notifications-in-app')->count());
        $left = DB::table('jobs')->where('queue', 'default')->get();
        $this->assertCount(1, $left, 'the default queue still holds its job');
        $this->assertSame($reportJob->id, $left->first()->id);
        $this->assertSame(0, (int) $left->first()->attempts, 'the default job was never attempted');
        $this->assertSame(1, $last['tasks']['queues']['default_pending_before']);
        $this->assertSame(1, $last['tasks']['queues']['default_pending_after']);
        $this->assertSame(0, DB::table('failed_jobs')->count());
    }

    public function test_the_queue_is_skipped_when_the_connection_is_sync(): void
    {
        config(['queue.default' => 'sync']);

        [$code, $last] = $this->run_(5);

        $this->assertSame(0, $code);
        $this->assertSame('skipped', $last['tasks']['queues']['status']);
    }

    // ---------------------------------------------------------------------------------------------------------- dry run

    public function test_a_dry_run_changes_nothing_and_records_nothing(): void
    {
        Storage::fake('local');
        $profile = CustomerProfile::factory()->for(User::factory())->create();
        Storage::disk('local')->put('uploads/expired.jpg', 'stale');
        $expired = Upload::factory()->for($profile)->expired()->create(['path' => 'uploads/expired.jpg']);
        $due = $this->stagedBatch('1');
        ImportBatch::query()->whereKey($due)->update(['retention_expires_at' => now('UTC')->subMinute()]);
        $this->followUp('2026-11-10');
        $inApp = Notification::factory()->forAdmin($this->admin)->create();
        ProcessNotificationJob::dispatch($inApp->id)->onQueue('notifications-in-app');
        $before = [DB::table('uploads')->count(), DB::table('notifications')->count(), DB::table('jobs')->count(), $this->businessFingerprint()];

        foreach ([0, 2, 8] as $hour) {
            $code = Artisan::call('scheduler:run-hourly', ['--hour' => $hour, '--dry-run' => true]);
            $this->assertSame(0, $code);
        }

        $this->assertSame($before, [DB::table('uploads')->count(), DB::table('notifications')->count(), DB::table('jobs')->count(), $this->businessFingerprint()]);
        $this->assertDatabaseHas('uploads', ['id' => $expired->id]);
        Storage::disk('local')->assertExists('uploads/expired.jpg');
        $this->assertNull(ImportBatch::query()->find($due)->pii_purged_at);
        $this->assertSame('pending', $inApp->fresh()->status->value);
        $this->assertNull(Cache::get(RunHourlyScheduler::LAST_RUN_KEY), 'a dry run does not record a last run');

        $this->artisan('uploads:purge-expired', ['--dry-run' => true])->expectsOutputToContain('1 expired upload(s) are due for removal')->assertSuccessful();
        $this->artisan('follow-ups:notify-due', ['--dry-run' => true])->expectsOutputToContain('1 follow-up reminder(s) are due today (nothing was sent)')->assertSuccessful();
        $this->assertSame(1, DB::table('uploads')->where('id', $expired->id)->count());
    }

    // ---------------------------------------------------------------------------------------------------------- failure, overlap, timeout, privacy

    public function test_one_failing_task_is_reported_the_others_still_run_and_the_exit_status_is_not_zero(): void
    {
        $this->mock(UploadServiceInterface::class, fn ($mock) => $mock->shouldReceive('purgeExpired')->andThrow(new \RuntimeException('Ahmed Hassan 0615551234 could not be purged')));
        $inApp = Notification::factory()->forAdmin($this->admin)->create();
        ProcessNotificationJob::dispatch($inApp->id)->onQueue('notifications-in-app');

        [$code, $last] = $this->run_(0);

        $this->assertNotSame(0, $code, 'a failed task makes the run fail');
        $this->assertSame('failed', $last['status']);
        $this->assertSame(['uploads'], $last['failed_tasks']);
        $this->assertSame('failed', $last['tasks']['uploads']['status']);
        $this->assertSame('RuntimeException', $last['tasks']['uploads']['error']);
        $this->assertSame('ok', $last['tasks']['queues']['status'], 'the queues were still drained');
        $this->assertSame('delivered', $inApp->fresh()->status->value);

        $recorded = (string) Cache::get(RunHourlyScheduler::LAST_RUN_KEY);
        $this->assertStringNotContainsString('Ahmed', $recorded, 'an exception message is never recorded');
        $this->assertStringNotContainsString('0615551234', $recorded);
    }

    public function test_a_failing_follow_up_task_does_not_stop_the_queue_or_hide_itself(): void
    {
        Artisan::command('follow-ups:notify-due {--dry-run}', fn () => 1)->purpose('replaced for this test');

        [$code, $last] = $this->run_(8);

        $this->assertNotSame(0, $code);
        $this->assertSame(['follow_ups'], $last['failed_tasks']);
        $this->assertArrayHasKey('queues', $last['tasks']);
    }

    public function test_the_output_and_the_record_contain_no_personal_data(): void
    {
        $this->stagedBatch('7');
        $this->followUp('2026-11-10');

        // What the command itself prints (the sub-commands' output is captured inside it), as Railway's logs would show it.
        $console = new BufferedOutput;
        Artisan::call('scheduler:run-hourly', ['--hour' => 8], $console);
        Artisan::call('scheduler:run-hourly', ['--hour' => 2, '--dry-run' => true], $console);
        $out = $console->fetch().(string) Cache::get(RunHourlyScheduler::LAST_RUN_KEY);
        $this->assertStringContainsString('"scheduler":"run-hourly"', $out, 'the summary line was printed');

        foreach (['Scheduler Person', 'Nobody Known', '61555', 'Demo Facility', $this->admin->email] as $personal) {
            $this->assertStringNotContainsString((string) $personal, $out);
        }
    }

    public function test_a_second_run_that_starts_while_one_is_active_does_nothing(): void
    {
        Storage::fake('local');
        $profile = CustomerProfile::factory()->for(User::factory())->create();
        $expired = Upload::factory()->for($profile)->expired()->create();
        $lock = Cache::lock(RunHourlyScheduler::LOCK_KEY, 120);
        $this->assertTrue($lock->get());

        [$code] = $this->run_(0);

        $this->assertSame(0, $code, 'Railway also skips an overlapping run; this is not a failure');
        $this->assertDatabaseHas('uploads', ['id' => $expired->id]); // the overlapping run did nothing
        $this->assertNull(Cache::get(RunHourlyScheduler::LAST_RUN_KEY));

        $lock->release();
        [$code] = $this->run_(0);
        $this->assertSame(0, $code);
        $this->assertDatabaseMissing('uploads', ['id' => $expired->id]);
    }

    public function test_a_run_that_runs_out_of_time_fails_loudly_and_does_not_start_more_work(): void
    {
        $this->mock(UploadServiceInterface::class, function ($mock): void {
            $mock->shouldReceive('purgeExpired')->andReturnUsing(function (): int {
                CarbonImmutable::setTestNow(now('UTC')->addMinutes(20)); // the purge "took" twenty minutes

                return 0;
            });
        });
        $inApp = Notification::factory()->forAdmin($this->admin)->create();
        ProcessNotificationJob::dispatch($inApp->id)->onQueue('notifications-in-app');

        [$code, $last] = $this->run_(0, ['--timeout' => 60]);

        $this->assertNotSame(0, $code);
        $this->assertSame('timeout', $last['tasks']['queues']['status']);
        $this->assertSame('pending', $inApp->fresh()->status->value, 'no new work was started after the deadline');
        $this->assertContains('queues', $last['failed_tasks']);
    }

    public function test_the_last_run_is_recorded_with_every_task(): void
    {
        [, $last] = $this->run_(8);

        $this->assertSame('ok', $last['status']);
        $this->assertFalse($last['dry_run']);
        $this->assertSame(8, $last['hour_utc']);
        $this->assertSame(['follow_ups', 'queues'], array_keys($last['tasks']));
        $this->assertSame([], $last['failed_tasks']);
        $this->assertNotEmpty($last['started_at']);
        $this->assertNotEmpty($last['finished_at']);
    }

    public function test_every_run_even_one_with_no_task_of_its_own_drains_the_notification_queues(): void
    {
        $inApp = Notification::factory()->forAdmin($this->admin)->create();
        ProcessNotificationJob::dispatch($inApp->id)->onQueue('notifications-in-app');

        [, $last] = $this->run_(23);

        $this->assertSame(['queues'], array_keys($last['tasks']));
        $this->assertSame('delivered', $inApp->fresh()->status->value);
    }

    public function test_the_whole_day_never_changes_business_data(): void
    {
        $employee = $this->makeEmployee('active', 'permanent');
        $this->makeAssignment($employee, $this->makeCompany('Demo Co'), null, 300, '2026-01-01');
        $this->stagedBatch('3');
        $this->followUp('2026-11-10');
        $before = $this->businessFingerprint();

        foreach (range(0, 23) as $hour) {
            $this->run_($hour);
        }

        $this->assertSame($before, $this->businessFingerprint());
    }
}
