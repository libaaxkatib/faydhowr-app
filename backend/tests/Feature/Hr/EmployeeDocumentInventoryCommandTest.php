<?php

namespace Tests\Feature\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesDocumentDisk;
use Tests\TestCase;

/**
 * `hr:documents-inventory` is a read-only diagnostic: these tests prove its counts are right, that it changes nothing
 * (database or files), that repeated runs agree, and that its output carries no paths, names or personal data.
 * Disposable data and a faked disk only.
 */
class EmployeeDocumentInventoryCommandTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private Employee $employee;

    private int $uploaderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeLocalDisk();
        $this->uploaderId = Admin::factory()->create()->id;
        $this->employee = Employee::query()->create([
            'employee_number' => 'EMP-424242',
            'full_name' => 'Personal Name Probe',
            'phone' => '+252615000111',
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function record(string $path, ?string $content, ?int $recordedSize = null, bool $current = true, string $fileName = 'sensitive-passport.pdf'): EmployeeDocument
    {
        if ($content !== null) {
            Storage::disk('local')->put($path, $content);
        }

        return EmployeeDocument::query()->create([
            'employee_id' => $this->employee->id,
            'admin_id' => $this->uploaderId,
            'file_name' => $fileName,
            'file_type' => 'pdf',
            'file_size' => $recordedSize ?? ($content !== null ? strlen($content) : 10),
            'file_path' => $path,
            'verification_status' => 'pending',
            'is_current' => $current,
        ]);
    }

    /** 3 verified (one superseded), 1 missing, 1 size mismatch, 1 unusable reference, and 2 stored files nobody refers to. */
    private function seedScenario(): void
    {
        $id = $this->employee->id;
        $this->record("employees/{$id}/documents/ok1.pdf", 'AAAA');
        $this->record("employees/{$id}/documents/ok2.pdf", 'BBBBBB');
        $this->record("employees/{$id}/documents/old.pdf", 'CC', current: false);
        $this->record("employees/{$id}/documents/gone.pdf", null);
        $this->record("employees/{$id}/documents/resized.pdf", 'DDDD', recordedSize: 999);
        $this->record('../../etc/passwd', null);

        Storage::disk('local')->put("employees/{$id}/documents/unreferenced-1.pdf", 'ORPHAN-ONE');
        Storage::disk('local')->put('employees/55/documents/unreferenced-2.pdf', 'ORPHAN-TWO');
        // Files elsewhere on the same disk must never be scanned or counted.
        Storage::disk('local')->put('framework/cache/data', 'SYSTEM');
        Storage::disk('local')->put("employees/{$id}/other/not-a-document.txt", 'OTHER');
        Storage::disk('local')->put('exports/payroll.xlsx', 'MONEY');
    }

    /** @return array<string, mixed> */
    private function runJson(string $options = ''): array
    {
        $exit = Artisan::call('hr:documents-inventory --json '.$options);
        $decoded = json_decode(Artisan::output(), true);
        $this->assertIsArray($decoded, 'The JSON report must be valid JSON.');
        $decoded['_exit'] = $exit;

        return $decoded;
    }

    /** @return array<string, mixed> */
    private function snapshot(): array
    {
        $files = [];
        foreach (Storage::disk('local')->allFiles() as $file) {
            $files[$file] = [Storage::disk('local')->size($file), Storage::disk('local')->lastModified($file), md5(Storage::disk('local')->get($file))];
        }

        return [
            'documents' => DB::table('employee_documents')->orderBy('id')->get()->toArray(),
            'employees' => DB::table('employees')->orderBy('id')->get()->toArray(),
            'audit_logs' => DB::table('audit_logs')->count(),
            'files' => $files,
        ];
    }

    public function test_it_counts_existing_missing_mismatched_and_invalid_records_exactly(): void
    {
        $this->seedScenario();

        $report = $this->runJson();

        $this->assertSame(0, $report['_exit']);
        $this->assertTrue($report['read_only']);
        $summary = $report['summary'];
        $this->assertSame(6, $summary['total_records']);
        $this->assertSame(5, $summary['current_records']);
        $this->assertSame(1, $summary['superseded_records']);
        $this->assertSame(3, $summary['verified']);
        $this->assertSame(1, $summary['size_mismatch']);
        $this->assertSame(1, $summary['missing']);
        $this->assertSame(1, $summary['invalid_reference']);
        $this->assertSame(0, $summary['unreadable']);
        $this->assertSame(0, $summary['storage_unavailable']);
        $this->assertSame(0, $summary['inspection_errors']);
        // Only employees/{id}/documents/* is scanned: 3 referenced + 1 mismatched + 2 unreferenced = 6 files, 2 of them unreferenced.
        $this->assertSame(6, $summary['files_scanned_for_unreferenced']);
        $this->assertSame(2, $summary['unreferenced_files']);
    }

    public function test_the_default_report_contains_counts_only(): void
    {
        $this->seedScenario();

        $report = $this->runJson();

        $this->assertNull($report['problem_records']);
        $this->assertNull($report['unreferenced_files']);
    }

    public function test_details_list_problems_by_internal_id_without_names_paths_or_personal_data(): void
    {
        $this->seedScenario();
        Artisan::call('hr:documents-inventory --json --details');
        $raw = Artisan::output();
        $report = json_decode($raw, true);

        $this->assertCount(3, $report['problem_records']);
        $this->assertEqualsCanonicalizing(['missing', 'size_mismatch', 'invalid_reference'], array_column($report['problem_records'], 'problem'));
        $this->assertCount(2, $report['unreferenced_files']);
        foreach ($report['problem_records'] as $row) {
            $this->assertSame(['document_id', 'employee_id', 'disk', 'current', 'uploaded_on', 'problem'], array_keys($row));
        }
        foreach ($report['unreferenced_files'] as $row) {
            $this->assertSame(['disk', 'employee_id', 'size_bytes', 'modified_on', 'reference'], array_keys($row));
            $this->assertSame(12, strlen($row['reference']));
        }

        foreach (['employees/', 'documents/', 'unreferenced-1', 'unreferenced-2', 'gone.pdf', 'sensitive-passport', 'Personal Name Probe', '+252615000111', 'EMP-424242', 'passwd', 'ORPHAN', 'http'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw, "The report must not contain '{$needle}'.");
        }
    }

    public function test_the_human_readable_report_is_also_free_of_paths_and_personal_data(): void
    {
        $this->seedScenario();

        $exit = Artisan::call('hr:documents-inventory --details');
        $output = Artisan::output();

        $this->assertSame(0, $exit);
        $this->assertStringContainsString('nothing was changed', $output);
        foreach (['employees/', 'sensitive-passport', 'Personal Name Probe', '+252615000111', 'EMP-424242'] as $needle) {
            $this->assertStringNotContainsString($needle, $output);
        }
    }

    public function test_it_changes_nothing_in_the_database_or_in_storage(): void
    {
        $this->seedScenario();
        $before = $this->snapshot();

        $this->runJson('--details');
        Artisan::call('hr:documents-inventory --details');
        Artisan::call('hr:documents-inventory --skip-orphans --fail-on-issues');

        $this->assertEquals($before, $this->snapshot());
    }

    public function test_repeated_runs_on_unchanged_data_give_identical_results(): void
    {
        $this->seedScenario();

        $first = $this->runJson('--details');
        $second = $this->runJson('--details');

        unset($first['generated_at'], $second['generated_at']);
        $this->assertSame($first, $second);
    }

    public function test_it_reports_a_clean_state_as_clean(): void
    {
        $this->record("employees/{$this->employee->id}/documents/only.pdf", 'FINE');

        $report = $this->runJson('--fail-on-issues');

        $this->assertSame(0, $report['_exit']);
        $this->assertSame(1, $report['summary']['verified']);
        $this->assertSame(0, $report['summary']['missing']);
        $this->assertSame(0, $report['summary']['unreferenced_files']);
    }

    public function test_it_with_no_records_reports_zero(): void
    {
        $report = $this->runJson();

        $this->assertSame(0, $report['summary']['total_records']);
        $this->assertSame(0, $report['summary']['unreferenced_files']);
    }

    public function test_fail_on_issues_turns_findings_into_a_failure_exit_code_and_only_then(): void
    {
        $this->seedScenario();

        $this->assertSame(0, Artisan::call('hr:documents-inventory'));
        $this->assertSame(1, Artisan::call('hr:documents-inventory --fail-on-issues'));
    }

    public function test_skipping_the_unreferenced_file_scan_leaves_it_unreported_not_zero(): void
    {
        $this->seedScenario();

        $report = $this->runJson('--skip-orphans');

        $this->assertNull($report['summary']['unreferenced_files']);
        $this->assertNull($report['summary']['files_scanned_for_unreferenced']);
        $this->assertSame(6, $report['summary']['total_records']);
    }

    public function test_when_the_storage_cannot_be_reached_it_says_so_instead_of_calling_every_file_missing(): void
    {
        $this->seedScenario();
        $this->makeStorageUnreachable();

        $exit = Artisan::call('hr:documents-inventory');
        $output = Artisan::output();

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not reachable', $output);
        $this->assertStringContainsString('Nothing was reported as missing', $output);
        $this->assertSame(6, DB::table('employee_documents')->count(), 'The records must be left alone.');
    }

    public function test_it_only_ever_reads_from_the_database(): void
    {
        $this->seedScenario();
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        Artisan::call('hr:documents-inventory --details --json');
        Artisan::call('hr:documents-inventory --fail-on-issues');

        $this->assertNotEmpty($statements, 'The command should have read the records.');
        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select', $sql, 'The inventory must run SELECT statements only, but ran: '.substr($sql, 0, 60));
        }
    }

    public function test_it_warns_when_every_record_points_at_a_file_that_is_not_in_the_storage(): void
    {
        $this->record("employees/{$this->employee->id}/documents/a.pdf", null);
        $this->record("employees/{$this->employee->id}/documents/b.pdf", null);

        $report = $this->runJson();

        $this->assertSame(2, $report['summary']['missing']);
        $this->assertCount(1, $report['warnings']);
        $this->assertStringContainsString('right storage is attached', $report['warnings'][0]);

        // A mixed result does not trigger the warning.
        $this->record("employees/{$this->employee->id}/documents/c.pdf", 'FINE');
        $this->assertSame([], $this->runJson()['warnings']);
    }

    public function test_an_unreachable_storage_is_not_recreated_by_looking_at_it(): void
    {
        $this->seedScenario();
        $this->makeStorageUnreachable();
        $root = (string) config('filesystems.disks.local.root');

        Artisan::call('hr:documents-inventory');

        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_the_json_report_carries_no_secrets(): void
    {
        $this->seedScenario();
        Artisan::call('hr:documents-inventory --json --details');
        $raw = strtolower(Artisan::output());

        foreach (['password', 'secret', 'token', 'app_key', 'base64:', 'authorization', 'bearer', 'api_key'] as $needle) {
            $this->assertStringNotContainsString($needle, $raw);
        }
    }

    public function test_an_unknown_disk_is_refused(): void
    {
        $this->assertSame(1, Artisan::call('hr:documents-inventory --disk=does-not-exist'));
        $this->assertStringContainsString('Unknown storage disk', Artisan::output());
    }
}
