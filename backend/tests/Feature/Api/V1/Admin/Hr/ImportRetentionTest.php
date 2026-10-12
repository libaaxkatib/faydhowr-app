<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Hr\Import\ImportRetention;
use Carbon\CarbonImmutable;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * Staging personal data is deleted 30 days after the upload (UTC); the non-sensitive audit trail stays.
 */
class ImportRetentionTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    /** Batch id the failure-injection listener should fail on (null = none). */
    private static ?int $failOnBatch = null;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->importSuperAdmin();
        self::$failOnBatch = null;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        self::$failOnBatch = null;

        parent::tearDown();
    }

    private const WAITING_HEADER = ['full_name', 'phone'];

    /** A staged waiting-list batch whose rows carry names and phone numbers. */
    private function stageWithPersonalData(string $suffix = '1'): int
    {
        $employee = $this->makeEmployee('applicant', 'permanent', '61555'.str_pad($suffix, 4, '0', STR_PAD_LEFT));
        $employee->update(['full_name' => "Retention Person {$suffix}"]);

        return $this->stageImport($this->token, 'waiting_list', $this->csvUpload(self::WAITING_HEADER, [["Retention Person {$suffix}", '61555'.str_pad($suffix, 4, '0', STR_PAD_LEFT)], ['Nobody Known', '61999000'.$suffix.'0']]));
    }

    private function personalDataRows(int $id): int
    {
        return ImportRow::query()->where('import_batch_id', $id)->where(fn ($q) => $q->whereNotNull('payload')->orWhereNotNull('name_key')->orWhereNotNull('phone_key')->orWhereNotNull('meta'))->count();
    }

    public function test_rows_hold_personal_data_while_retained(): void
    {
        $id = $this->stageWithPersonalData();

        $this->assertSame(2, $this->personalDataRows($id));
        $row = ImportRow::query()->where('import_batch_id', $id)->orderBy('row_number')->firstOrFail();
        $this->assertSame('retention person 1', $row->name_key);
        $this->assertStringContainsString('Retention Person 1', json_encode($row->payload));
    }

    public function test_nothing_is_purged_before_thirty_days_and_everything_after_in_utc(): void
    {
        CarbonImmutable::setTestNow('2026-10-12 08:00:00 UTC');
        $id = $this->stageWithPersonalData();

        // One second before the cutoff (2026-11-11 08:00:00 UTC): untouched.
        CarbonImmutable::setTestNow('2026-11-11 07:59:59 UTC');
        $this->assertSame(['purged' => 0, 'failed' => []], app(ImportRetention::class)->purgeExpired());
        $this->assertSame(2, $this->personalDataRows($id));
        $this->assertNull(ImportBatch::query()->find($id)->pii_purged_at);

        // At the cutoff: purged.
        CarbonImmutable::setTestNow('2026-11-11 08:00:00 UTC');
        $this->assertSame(['purged' => 1, 'failed' => []], app(ImportRetention::class)->purgeExpired());
        $this->assertSame(0, $this->personalDataRows($id));
        $this->assertSame('2026-11-11 08:00:00', ImportBatch::query()->find($id)->pii_purged_at->utc()->format('Y-m-d H:i:s'));
    }

    public function test_the_cutoff_is_measured_in_utc_even_when_the_app_timezone_is_different(): void
    {
        config(['app.timezone' => 'Africa/Mogadishu']);
        date_default_timezone_set('Africa/Mogadishu');

        try {
            CarbonImmutable::setTestNow('2026-10-12 08:00:00 UTC');
            $id = $this->stageWithPersonalData();
            $expires = DB::table('import_batches')->where('id', $id)->value('retention_expires_at');
            $this->assertSame('2026-11-11 08:00:00', substr((string) $expires, 0, 19), 'Stored as UTC wall-clock time.');
        } finally {
            date_default_timezone_set('UTC');
        }
    }

    public function test_the_purge_removes_personal_data_and_keeps_only_the_non_sensitive_audit_trail(): void
    {
        $id = $this->stageWithPersonalData();
        $before = ImportBatch::query()->find($id);
        $rowsBefore = ImportRow::query()->where('import_batch_id', $id)->orderBy('row_number')->get();
        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        app(ImportRetention::class)->purgeExpired();

        $batch = ImportBatch::query()->find($id);
        $this->assertNotNull($batch->pii_purged_at);
        $this->assertSame($before->created_by, $batch->created_by, 'Who imported is kept.');
        $this->assertSame($before->total_rows, $batch->total_rows);
        $this->assertSame($before->review_rows, $batch->review_rows);
        $this->assertSame($before->source_sha256, $batch->source_sha256);
        $this->assertSame($before->created_at->toDateTimeString(), $batch->created_at->toDateTimeString(), 'When it was imported is kept.');

        foreach (ImportRow::query()->where('import_batch_id', $id)->orderBy('row_number')->get() as $i => $row) {
            $this->assertNull($row->payload);
            $this->assertNull($row->name_key);
            $this->assertNull($row->phone_key);
            $this->assertNull($row->meta);
            $this->assertSame($rowsBefore[$i]->row_number, $row->row_number);
            $this->assertSame($rowsBefore[$i]->entity_id, $row->entity_id);

            // A row that was never imported can no longer be, so it is closed with a code; every other
            // row keeps the code it had.
            $this->assertSame($rowsBefore[$i]->status->value === 'valid' ? 'RETENTION_EXPIRED' : $rowsBefore[$i]->code, $row->code);
        }

        // No name or phone survives anywhere in the import tables.
        $dump = json_encode([DB::table('import_batches')->get(), DB::table('import_rows')->get()]);
        $this->assertStringNotContainsString('Retention Person', $dump);
        $this->assertStringNotContainsString('61555', $dump);
    }

    public function test_the_api_shows_no_details_after_the_purge(): void
    {
        $id = $this->stageWithPersonalData();
        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);
        app(ImportRetention::class)->purgeExpired();

        $rows = $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->assertOk();
        $this->assertNull($rows->json('data.0.details'));
        $this->assertNull($rows->json('data.0.meta'));
        $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->assertOk()->assertJsonPath('data.details_deleted', true);
        $this->confirmImport($this->token, $id)->assertStatus(409);
    }

    public function test_running_the_purge_repeatedly_is_harmless(): void
    {
        $id = $this->stageWithPersonalData();
        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        $this->assertSame(1, app(ImportRetention::class)->purgeExpired()['purged']);
        $snapshot = [DB::table('import_batches')->get()->toJson(), DB::table('import_rows')->get()->toJson()];

        $this->assertSame(['purged' => 0, 'failed' => []], app(ImportRetention::class)->purgeExpired());
        $this->assertSame(0, Artisan::call('hr:purge-import-staging'));
        $this->assertSame($snapshot, [DB::table('import_batches')->get()->toJson(), DB::table('import_rows')->get()->toJson()]);
    }

    public function test_a_failing_batch_does_not_block_the_others_and_is_retried_next_time(): void
    {
        $a = $this->stageWithPersonalData('1');
        $b = $this->stageWithPersonalData('2');
        $c = $this->stageWithPersonalData('3');
        ImportBatch::query()->update(['retention_expires_at' => now('UTC')->subMinute()]);

        self::$failOnBatch = $b;
        ImportBatch::updating(function (ImportBatch $batch): void {
            if ($batch->id === self::$failOnBatch && $batch->isDirty('pii_purged_at')) {
                throw new \RuntimeException('simulated failure while purging');
            }
        });

        $result = app(ImportRetention::class)->purgeExpired();

        $this->assertSame(2, $result['purged']);
        $this->assertSame([$b], $result['failed']);
        $this->assertSame(0, $this->personalDataRows($a));
        $this->assertSame(0, $this->personalDataRows($c));
        $this->assertSame(2, $this->personalDataRows($b), 'The failed batch was rolled back untouched, not half-purged.');
        $this->assertNull(ImportBatch::query()->find($b)->pii_purged_at);

        // The command reports the failure with a non-zero exit code...
        $this->assertSame(1, Artisan::call('hr:purge-import-staging'));
        $this->assertStringContainsString("Could not purge batch id(s): {$b}", Artisan::output());

        // ...and the next run, once the problem is gone, finishes the job.
        self::$failOnBatch = null;
        $this->assertSame(0, Artisan::call('hr:purge-import-staging'));
        $this->assertSame(0, $this->personalDataRows($b));
        $this->assertNotNull(ImportBatch::query()->find($b)->pii_purged_at);
    }

    public function test_an_unconfirmed_expired_batch_is_closed_and_its_pending_rows_are_marked_not_imported(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(['name', 'category'], [['Pending Co', 'company'], ['', 'company']]));
        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        app(ImportRetention::class)->purgeExpired();

        $batch = ImportBatch::query()->find($id);
        $this->assertSame('cancelled', $batch->status->value);
        $this->assertSame(1, ImportRow::query()->where('import_batch_id', $id)->where('code', 'RETENTION_EXPIRED')->where('status', 'skipped')->count());
        $this->assertSame(0, ClientCompany::query()->count(), 'Nothing is imported by an expiry.');
    }

    public function test_a_half_processed_expired_batch_is_completed_with_the_rest_skipped(): void
    {
        $rows = array_map(fn (int $i): array => ["Half Co {$i}", 'company'], range(1, 150));
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(['name', 'category'], $rows));
        $this->confirmImport($this->token, $id)->assertOk();
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertOk();
        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        app(ImportRetention::class)->purgeExpired();

        $batch = ImportBatch::query()->find($id);
        $this->assertSame('completed', $batch->status->value);
        $this->assertSame(100, $batch->imported_rows);
        $this->assertSame(50, $batch->skipped_rows);
        $this->assertSame(100, ClientCompany::query()->count());
    }

    public function test_a_cancelled_batch_is_purged_immediately_even_before_it_expires(): void
    {
        $id = $this->stageWithPersonalData();
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/cancel")->assertOk();

        $this->assertSame(0, $this->personalDataRows($id));
        $this->assertNotNull(ImportBatch::query()->find($id)->pii_purged_at);
    }

    public function test_dry_run_changes_nothing(): void
    {
        $id = $this->stageWithPersonalData();
        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        $this->assertSame(0, Artisan::call('hr:purge-import-staging', ['--dry-run' => true]));
        $this->assertStringContainsString('1 import batch(es) are due', Artisan::output());
        $this->assertSame(2, $this->personalDataRows($id));
    }

    public function test_a_new_upload_also_purges_expired_batches_so_retention_does_not_depend_on_the_scheduler(): void
    {
        $old = $this->stageWithPersonalData('1');
        ImportBatch::query()->whereKey($old)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        $this->stageWithPersonalData('2');

        $this->assertSame(0, $this->personalDataRows($old));
    }

    public function test_the_purge_is_scheduled_daily_at_a_fixed_utc_time(): void
    {
        $event = collect(app(Schedule::class)->events())->first(fn ($e) => str_contains((string) $e->command, 'hr:purge-import-staging'));

        $this->assertNotNull($event, 'hr:purge-import-staging is scheduled.');
        $this->assertSame('30 2 * * *', $event->expression);
        $this->assertSame('UTC', (string) $event->timezone);
    }

    public function test_the_purge_never_touches_employees_companies_or_their_history(): void
    {
        $employee = $this->makeEmployee('waiting');
        $employee->statusHistories()->create(['from_status' => 'applicant', 'to_status' => 'waiting', 'note' => 'keep']);
        ClientCompany::query()->create(['name' => 'Keep Co', 'category' => 'company', 'status' => 'active']);

        $id = $this->stageWithPersonalData();
        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);

        // Snapshot everything outside the import tables, then purge.
        $snapshot = fn (): array => [
            Employee::query()->orderBy('id')->get()->toJson(),
            ClientCompany::query()->orderBy('id')->get()->toJson(),
            DB::table('employee_status_histories')->orderBy('id')->get()->toJson(),
            DB::table('audit_logs')->orderBy('id')->get()->toJson(),
        ];
        $before = $snapshot();

        app(ImportRetention::class)->purgeExpired();

        $this->assertSame($before, $snapshot(), 'Employees, companies, status history and the audit log are untouched by the purge.');
    }
}
