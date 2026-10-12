<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\ImportBatchStatus;
use App\Models\Admin;
use App\Models\ClientCompany;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Hr\Import\ImportProcessor;
use App\Support\Hr\Import\WorkbookReader;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * The shared import engine, exercised through the company import: workbook validation, staging and
 * preview, confirmation, bounded processing, partial success, idempotency, concurrency and reporting.
 */
class ImportFoundationTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    private const HEADER = ['name', 'category', 'aliases'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->importSuperAdmin();
    }

    /** @return list<list<string>> */
    private function companyRows(int $count, string $prefix = 'Company'): array
    {
        return array_map(fn (int $i): array => ["{$prefix} {$i}", $i % 2 === 0 ? 'company' : 'guri', ''], range(1, $count));
    }

    // ---------------------------------------------------------------- workbook validation

    public function test_only_xlsx_and_csv_files_are_accepted(): void
    {
        foreach (['list.xls', 'list.pdf', 'list.xlsm', 'list.exe', 'list.txt'] as $name) {
            $this->upload($this->token, 'companies', UploadedFile::fake()->createWithContent($name, "name,category\nA,company\n"))
                ->assertStatus(422)->assertJsonPath('error_code', 'FILE_TYPE_NOT_ALLOWED');
        }

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_a_binary_file_renamed_to_csv_or_xlsx_is_refused_by_its_content(): void
    {
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg==');

        $this->upload($this->token, 'companies', UploadedFile::fake()->createWithContent('list.csv', $png))
            ->assertStatus(422)->assertJsonPath('error_code', 'FILE_CONTENT_MISMATCH');

        $this->upload($this->token, 'companies', UploadedFile::fake()->createWithContent('list.xlsx', "name,category\nA,company\n"))
            ->assertStatus(422)->assertJsonPath('error_code', 'FILE_CONTENT_MISMATCH');
    }

    public function test_an_empty_or_oversized_file_is_refused(): void
    {
        $this->upload($this->token, 'companies', UploadedFile::fake()->createWithContent('empty.csv', ''))
            ->assertStatus(422);

        $big = "name,category\n".str_repeat("Some Company,company\n", 400000);
        $this->upload($this->token, 'companies', UploadedFile::fake()->createWithContent('big.csv', $big))
            ->assertStatus(422);

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_missing_required_columns_are_named_and_nothing_is_staged(): void
    {
        $this->upload($this->token, 'companies', $this->csvUpload(['name'], [['Acme']]))
            ->assertStatus(422)->assertJsonPath('error_code', 'MISSING_COLUMNS');

        $this->upload($this->token, 'companies', $this->csvUpload(['foo', 'bar'], [['1', '2']]))
            ->assertStatus(422)->assertJsonPath('error_code', 'HEADER_NOT_FOUND');

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_a_header_without_data_rows_and_a_duplicated_column_are_refused(): void
    {
        $this->upload($this->token, 'companies', $this->csvUpload(self::HEADER, []))
            ->assertStatus(422)->assertJsonPath('error_code', 'FILE_EMPTY');

        $this->upload($this->token, 'companies', $this->csvUpload(['name', 'company name', 'category'], [['A', 'B', 'company']]))
            ->assertStatus(422)->assertJsonPath('error_code', 'DUPLICATE_COLUMN');
    }

    public function test_more_than_the_row_limit_is_refused(): void
    {
        $this->upload($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(WorkbookReader::MAX_ROWS + 1)))
            ->assertStatus(422)->assertJsonPath('error_code', 'TOO_MANY_ROWS');

        $this->assertSame(0, ImportBatch::query()->count());
    }

    public function test_a_real_xlsx_is_read_and_stray_cells_far_to_the_right_are_ignored(): void
    {
        $file = $this->xlsxUpload(self::HEADER, [['Acme Trading', 'company', ''], ['Beta Guri', 'guri', 'Beta G @ rows 4,5']], 'plan.xlsx', ['XFD3' => 'stray', 'AZ2' => 'stray']);

        $response = $this->upload($this->token, 'companies', $file)->assertCreated();

        $response->assertJsonPath('data.counts.total', 2)->assertJsonPath('data.counts.valid', 2);
    }

    public function test_unrecognised_headers_are_reported_back_and_not_imported(): void
    {
        $response = $this->upload($this->token, 'companies', $this->csvUpload(['name', 'category', 'favourite colour'], [['Acme', 'company', 'blue']]))->assertCreated();

        $this->assertSame(['favourite colour'], $response->json('data.unrecognised_headers'));
    }

    public function test_common_header_spellings_and_a_utf8_bom_are_understood(): void
    {
        $file = UploadedFile::fake()->createWithContent('plan.csv', "\xEF\xBB\xBFCompany Name;Type;Aliases\nAcme;company;\n");

        $this->upload($this->token, 'companies', $file)->assertCreated()->assertJsonPath('data.counts.valid', 1);
    }

    public function test_the_uploaded_workbook_is_never_stored(): void
    {
        Storage::fake('local');
        Storage::fake('public');

        $this->upload($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(3)))->assertCreated();

        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
        $this->assertSame([], glob(storage_path('app/private/*.csv')) ?: []);
        $columns = DB::getSchemaBuilder()->getColumnListing('import_batches');
        $this->assertNotContains('file_path', $columns);
        $this->assertNotContains('original_name', $columns);
    }

    // ---------------------------------------------------------------- staging and preview

    public function test_staging_classifies_every_row_and_writes_nothing_to_the_business_tables(): void
    {
        ClientCompany::query()->create(['name' => 'Existing Co', 'category' => 'company', 'status' => 'active']);

        $rows = [
            ['New Co', 'company', ''],
            ['Existing Co', 'company', ''],
            ['', 'company', ''],
            ['Bad Category Co', 'hotel', ''],
            ['New Co', 'company', ''],
        ];

        $response = $this->upload($this->token, 'companies', $this->csvUpload(self::HEADER, $rows))->assertCreated();

        $response->assertJsonPath('data.status', 'staged')
            ->assertJsonPath('data.counts.total', 5)
            ->assertJsonPath('data.counts.valid', 1)
            ->assertJsonPath('data.counts.invalid', 2)
            ->assertJsonPath('data.counts.duplicate', 2)
            ->assertJsonPath('data.counts.review', 0)
            ->assertJsonPath('data.pending_rows', 1);

        $this->assertSame(1, ClientCompany::query()->count(), 'Nothing is created by an upload.');
    }

    public function test_the_preview_is_paginated_filterable_and_keeps_sheet_row_numbers(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [...$this->companyRows(60), ['', 'company', '']]));

        $page = $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows?per_page=25&page=2")->assertOk();
        $page->assertJsonPath('meta.total', 61)->assertJsonPath('meta.last_page', 3)->assertJsonCount(25, 'data');
        $this->assertSame(27, $page->json('data.0.row_number'), 'Row numbers are the sheet row numbers (header is row 1).');

        $invalid = $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows?status=invalid")->assertOk();
        $invalid->assertJsonCount(1, 'data')->assertJsonPath('data.0.code', 'NAME_REQUIRED')->assertJsonPath('data.0.row_number', 62);
    }

    public function test_import_history_lists_who_uploaded_what(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(2)));

        $history = $this->as($this->token)->getJson(self::IMPORTS)->assertOk();
        $history->assertJsonPath('data.0.id', $id)->assertJsonPath('data.0.type', 'companies')->assertJsonPath('data.0.status', 'staged');
        $this->assertNotNull($history->json('data.0.created_by'));
    }

    // ---------------------------------------------------------------- confirmation and processing

    public function test_confirmation_must_be_explicit_and_nothing_is_processed_before_it(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(3)));

        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/confirm")->assertStatus(422);
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertStatus(409)->assertJsonPath('error_code', 'IMPORT_NOT_CONFIRMED');

        $this->assertSame(0, ClientCompany::query()->count());
    }

    public function test_processing_is_bounded_to_one_batch_per_call_and_reaches_completion(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(250)));
        $this->confirmImport($this->token, $id)->assertOk()->assertJsonPath('data.status', 'processing');

        $imported = [];

        foreach ([100, 200, 250] as $expected) {
            $response = $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertOk();
            $imported[] = $response->json('data.counts.imported');
            $this->assertSame($expected, ClientCompany::query()->count());
        }

        $this->assertSame([100, 200, 250], $imported);
        $final = $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->assertOk();
        $final->assertJsonPath('data.status', 'completed')->assertJsonPath('data.pending_rows', 0)->assertJsonPath('data.counts.imported', 250);
        $this->assertNotNull($final->json('data.completed_at'));
        $this->assertNotNull($final->json('data.confirmed_at'));
    }

    public function test_partial_success_valid_rows_import_and_invalid_rows_are_only_reported(): void
    {
        $rows = [['Good One', 'company', ''], ['', 'company', ''], ['Good Two', 'guri', ''], ['Bad Cat', 'x', '']];
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $rows));

        $done = $this->runImport($this->token, $id);

        $done->assertJsonPath('data.counts.imported', 2)->assertJsonPath('data.counts.invalid', 2);
        $this->assertSame(['Good One', 'Good Two'], ClientCompany::query()->orderBy('id')->pluck('name')->all());
        $this->assertSame(0, ImportRow::query()->where('import_batch_id', $id)->where('status', 'invalid')->where('processed_at', '!=', null)->count());
    }

    public function test_a_company_that_exists_is_skipped_and_never_updated(): void
    {
        $existing = ClientCompany::query()->create(['name' => 'Acme Trading', 'category' => 'company', 'status' => 'active', 'notes' => 'keep me']);
        $before = $existing->fresh()->toArray();

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['ACME  trading', 'guri', 'Acme @ row 9']]));
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.duplicate', 1)->assertJsonPath('data.counts.imported', 0);

        $this->assertSame($before, $existing->fresh()->toArray(), 'The existing company is untouched.');
        $this->assertSame(1, ClientCompany::query()->count());
        $this->assertDatabaseCount('client_company_aliases', 0);
    }

    public function test_a_row_that_became_a_duplicate_after_the_preview_is_skipped_at_confirmation(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['Late Co', 'company', ''], ['Other Co', 'company', '']]));
        ClientCompany::query()->create(['name' => 'Late Co', 'category' => 'guri', 'status' => 'active']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 1)->assertJsonPath('data.counts.skipped', 1);

        $this->assertSame('guri', ClientCompany::query()->where('name', 'Late Co')->firstOrFail()->category->value, 'The company that already existed keeps its own category.');
        $this->assertSame(2, ClientCompany::query()->count());
    }

    public function test_one_row_that_errors_is_rolled_back_alone_and_the_others_still_import(): void
    {
        ClientCompany::creating(function (ClientCompany $company): void {
            if ($company->name === 'Boom Co') {
                throw new \RuntimeException('simulated database failure');
            }
        });

        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['First Co', 'company', ''], ['Boom Co', 'company', ''], ['Third Co', 'company', '']]));
        $done = $this->runImport($this->token, $id);

        $done->assertJsonPath('data.counts.imported', 2)->assertJsonPath('data.counts.failed', 1)->assertJsonPath('data.status', 'completed');
        $this->assertSame(['First Co', 'Third Co'], ClientCompany::query()->orderBy('id')->pluck('name')->all());
        $failed = ImportRow::query()->where('import_batch_id', $id)->where('status', 'failed')->firstOrFail();
        $this->assertSame('UNEXPECTED_ERROR', $failed->code);
        $this->assertStringNotContainsString('Boom', (string) $failed->message, 'Messages never contain imported data.');
    }

    // ---------------------------------------------------------------- idempotency and concurrency

    public function test_uploading_the_same_file_again_is_refused_and_points_to_the_existing_batch(): void
    {
        $rows = $this->companyRows(3);
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $rows));

        $again = $this->upload($this->token, 'companies', $this->csvUpload(self::HEADER, $rows, 'renamed.csv'));
        $again->assertStatus(409)->assertJsonPath('error_code', 'DUPLICATE_IMPORT')->assertJsonPath('errors.existing_batch_id', $id);

        $this->runImport($this->token, $id);
        $this->upload($this->token, 'companies', $this->csvUpload(self::HEADER, $rows))
            ->assertStatus(409)->assertJsonPath('errors.existing_status', 'completed');

        $this->assertSame(1, ImportBatch::query()->count());
        $this->assertSame(3, ClientCompany::query()->count(), 'A repeat can never import twice.');
    }

    public function test_a_cancelled_batch_frees_the_file_to_be_uploaded_again(): void
    {
        $rows = $this->companyRows(2);
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $rows));

        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/cancel")->assertOk()->assertJsonPath('data.status', 'cancelled');
        $this->upload($this->token, 'companies', $this->csvUpload(self::HEADER, $rows))->assertCreated();
    }

    public function test_the_database_itself_rejects_two_live_batches_for_the_same_file(): void
    {
        $attributes = ['type' => 'companies', 'status' => 'staged', 'source_sha256' => str_repeat('a', 64), 'source_size' => 10, 'source_extension' => 'csv', 'retention_expires_at' => now()->addDays(30), 'created_at' => now(), 'updated_at' => now()];

        DB::table('import_batches')->insert($attributes);
        $this->expectException(QueryException::class);
        DB::table('import_batches')->insert($attributes);
    }

    public function test_confirming_twice_starts_one_run_and_one_audit_entry(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(2)));

        $this->confirmImport($this->token, $id)->assertOk();
        $this->confirmImport($this->token, $id)->assertOk()->assertJsonPath('data.status', 'processing');

        $this->assertSame(1, DB::table('audit_logs')->where('description', 'like', "HR import #{$id} (companies) confirmed.%")->count());
    }

    public function test_a_second_processor_is_turned_away_while_a_batch_is_being_processed(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(5)));
        $this->confirmImport($this->token, $id)->assertOk();

        // Another request holds the lease.
        ImportBatch::query()->whereKey($id)->update(['lease_token' => 'other-request', 'lease_expires_at' => CarbonImmutable::now('UTC')->addSeconds(60)]);

        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertStatus(409)->assertJsonPath('error_code', 'IMPORT_BUSY');
        $this->assertSame(0, ClientCompany::query()->count(), 'The busy call processed nothing.');

        // The lease expires (the other request died): processing continues from where it was.
        ImportBatch::query()->whereKey($id)->update(['lease_expires_at' => CarbonImmutable::now('UTC')->subSecond()]);
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertOk()->assertJsonPath('data.status', 'completed');
        $this->assertSame(5, ClientCompany::query()->count());
        $this->assertNull(ImportBatch::query()->find($id)->lease_token, 'The lease is released.');
    }

    public function test_rows_that_are_already_handled_are_never_processed_twice(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(4)));
        $this->runImport($this->token, $id);
        $this->assertSame(4, ClientCompany::query()->count());

        // Force the batch back to "processing": the rows are already imported, so nothing repeats.
        ImportBatch::query()->whereKey($id)->update(['status' => 'processing', 'completed_at' => null]);
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertOk();

        $this->assertSame(4, ClientCompany::query()->count());
        $this->assertSame(4, ImportBatch::query()->find($id)->imported_rows);
    }

    public function test_processor_two_overlapping_calls_do_not_double_import(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(10)));
        $this->confirmImport($this->token, $id)->assertOk();
        $processor = app(ImportProcessor::class);
        $admin = Admin::query()->firstOrFail();

        $processor->processNext(ImportBatch::query()->findOrFail($id), $admin);
        $processor->processNext(ImportBatch::query()->findOrFail($id), $admin);

        $this->assertSame(10, ClientCompany::query()->count());
        $this->assertSame(10, ClientCompany::query()->distinct()->count('name'));
    }

    // ---------------------------------------------------------------- cancel, report, audit

    public function test_cancelling_deletes_the_staging_data_and_only_staged_batches_can_be_cancelled(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(3)));
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/cancel")->assertOk()->assertJsonPath('data.details_deleted', true);

        $this->assertSame(0, ImportRow::query()->where('import_batch_id', $id)->whereNotNull('payload')->count());
        $this->confirmImport($this->token, $id)->assertStatus(409)->assertJsonPath('error_code', 'IMPORT_CANCELLED');

        $other = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(2, 'Other')));
        $this->runImport($this->token, $other);
        $this->as($this->token)->postJson(self::IMPORTS."/{$other}/cancel")->assertStatus(409)->assertJsonPath('error_code', 'IMPORT_NOT_CANCELLABLE');
    }

    public function test_the_row_level_report_lists_every_row_without_names_and_neutralises_formulas(): void
    {
        $rows = [['Visible Company Name', 'company', ''], ['', 'company', ''], ['=HYPERLINK("http://evil")', 'company', ''], ['Bad', 'zzz', '']];
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $rows));
        $this->runImport($this->token, $id);

        $csv = $this->as($this->token)->get(self::IMPORTS."/{$id}/report")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv, "\xEF\xBB\xBF")))));

        $this->assertSame('row,status,code,message,record_type,record_id', $lines[0]);
        $this->assertCount(5, $lines);
        $this->assertStringNotContainsString('Visible Company Name', $csv);
        $this->assertStringContainsString('NAME_REQUIRED', $csv);
        $this->assertStringContainsString('CATEGORY_INVALID', $csv);
        $this->assertStringContainsString('IMPORTED', $csv);

        $onlyInvalid = $this->as($this->token)->get(self::IMPORTS."/{$id}/report?status=invalid")->assertOk()->streamedContent();
        $this->assertStringNotContainsString('IMPORTED', $onlyInvalid);
    }

    public function test_the_import_is_recorded_in_the_audit_log_with_who_and_totals(): void
    {
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, [['A Co', 'company', ''], ['', 'company', '']]));
        $this->runImport($this->token, $id);

        $completed = DB::table('audit_logs')->where('description', "HR import #{$id} (companies) completed.")->first();
        $this->assertNotNull($completed);
        $this->assertNotNull($completed->admin_id);
        $metadata = json_decode($completed->metadata, true);
        $this->assertSame(1, $metadata['imported']);
        $this->assertSame(1, $metadata['not_imported_invalid']);
        $this->assertSame(ImportBatchStatus::Completed, ImportBatch::query()->find($id)->status);
    }

    public function test_the_staging_retention_cutoff_is_thirty_days_from_the_upload_in_utc(): void
    {
        CarbonImmutable::setTestNow('2026-10-12 08:00:00');
        $id = $this->stageImport($this->token, 'companies', $this->csvUpload(self::HEADER, $this->companyRows(1)));

        $this->assertSame('2026-11-11 08:00:00', ImportBatch::query()->find($id)->retention_expires_at->utc()->format('Y-m-d H:i:s'));
        CarbonImmutable::setTestNow();
    }
}
