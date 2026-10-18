<?php

namespace Tests\Feature\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Tests\Support\FakesDocumentDisk;
use Tests\TestCase;

/**
 * Regression guard for the incident in which an earlier test draft deleted the real `storage/app/private` folder.
 *
 * Everything here is non-destructive: the real folder is only ever READ (listed and hashed) to prove that the document
 * test helpers, the download endpoint and the inventory command leave it exactly as it was.
 */
class DocumentTestStorageIsolationTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    /** @return array<string, string>|null null when the folder does not exist (for example on a fresh checkout without it) */
    private function snapshotOfTheRealPrivateFolder(): ?array
    {
        $root = storage_path('app/private');

        if (! is_dir($root)) {
            return null;
        }

        $snapshot = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);

        foreach ($iterator as $item) {
            $relative = substr($item->getPathname(), strlen($root));
            $snapshot[$relative] = $item->isDir() ? 'dir' : $item->getSize().':'.$item->getMTime().':'.hash_file('sha256', $item->getPathname());
        }

        ksort($snapshot);

        return $snapshot;
    }

    private function seedOneDocumentOnTheFakeDisk(): array
    {
        $employee = Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => 'Isolation Probe',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
        Storage::disk('local')->put("employees/{$employee->id}/documents/probe.pdf", 'PROBE');
        $document = EmployeeDocument::query()->create([
            'employee_id' => $employee->id,
            'admin_id' => Admin::factory()->create()->id,
            'file_name' => 'probe.pdf',
            'file_type' => 'pdf',
            'file_size' => 5,
            'file_path' => "employees/{$employee->id}/documents/probe.pdf",
            'verification_status' => 'pending',
            'is_current' => true,
        ]);

        return [$employee, $document];
    }

    public function test_the_document_helpers_and_commands_leave_the_real_private_storage_exactly_as_it_was(): void
    {
        $before = $this->snapshotOfTheRealPrivateFolder();

        $this->fakeLocalDisk(['throw' => false]);
        [$employee, $document] = $this->seedOneDocumentOnTheFakeDisk();
        $token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->get("/api/v1/admin/hr/employees/{$employee->id}/documents/{$document->id}/download")->assertOk();
        Artisan::call('hr:documents-inventory --details --json');
        $this->makeStorageUnreachable();
        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}/documents/{$document->id}/download")->assertStatus(503);
        Artisan::call('hr:documents-inventory');

        $this->assertSame($before, $this->snapshotOfTheRealPrivateFolder(), 'The real storage/app/private folder must be untouched by document tests.');
    }

    public function test_every_test_gets_a_private_disk_root_outside_the_real_application_storage(): void
    {
        // No helper is called here: this proves the base TestCase safety net on its own.
        $root = str_replace('\\', '/', (string) config('filesystems.disks.local.root'));
        $realPrivate = str_replace('\\', '/', storage_path('app/private'));

        $this->assertStringNotContainsString($realPrivate, $root);
        $this->assertStringContainsString('storage/framework/testing', $root);
    }

    public function test_a_test_may_leave_files_on_its_isolated_private_disk(): void
    {
        // Written on purpose: the next test (below) proves that nothing a test leaves behind is visible to another test.
        $this->assertSame([], Storage::disk('local')->allFiles());
        Storage::disk('local')->put('left-behind-by-the-previous-test.txt', 'x');
        $this->assertSame(['left-behind-by-the-previous-test.txt'], Storage::disk('local')->allFiles());
    }

    public function test_the_next_test_starts_with_an_empty_isolated_private_disk(): void
    {
        $this->assertSame([], Storage::disk('local')->allFiles(), 'Files left by an earlier test must never leak into another test.');
    }

    public function test_the_helper_refuses_a_root_inside_the_real_application_storage(): void
    {
        $refuse = new \ReflectionMethod($this, 'refuseUnlessIsolated');

        foreach ([storage_path('app/private'), storage_path('app/private/employees'), storage_path('app'), storage_path('app/public'), storage_path(), sys_get_temp_dir()] as $unsafe) {
            try {
                $refuse->invoke($this, $unsafe);
                $this->fail("Should have refused: {$unsafe}");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('not isolated', $e->getMessage());
            }
        }

        $refuse->invoke($this, storage_path('framework/testing/disks/local')); // an isolated root is accepted
        $this->addToAssertionCount(1);
    }

    public function test_the_document_tests_and_helpers_contain_no_bulk_or_recursive_delete_operations(): void
    {
        // A static guard: these are the calls that wiped the real folder. A test that really needs one must be reviewed on its own.
        $forbidden = ['delete'.'Directory', 'clean'.'Directory', 'shell'.'_exec', 'pass'.'thru(', 'proc'.'_open', 'rm -', 'Remove-'.'Item', 'File::'.'delete', 'rmdir'.'Recursive', 'array_map(\'unlink\''];
        $files = [
            base_path('tests/Support/FakesDocumentDisk.php'),
            base_path('tests/Feature/Api/V1/Admin/Hr/EmployeeDocumentFileHandlingTest.php'),
            base_path('tests/Feature/Hr/EmployeeDocumentInventoryCommandTest.php'),
            base_path('tests/Feature/Hr/DocumentStorageTest.php'),
            base_path('tests/Feature/Hr/DocumentS3AdapterTest.php'),
            base_path('tests/Feature/Hr/DocumentRehearsalEmulatedTest.php'),
            base_path('tests/Support/FakeS3Server.php'),
            base_path('tests/Feature/Hr/DocumentTestStorageIsolationTest.php'),
            base_path('tests/TestCase.php'),
        ];

        foreach ($files as $file) {
            $this->assertFileExists($file);
            $source = (string) file_get_contents($file);
            // This guard names the patterns, so skip its own list line when scanning itself.
            if (str_ends_with(str_replace('\\', '/', $file), 'DocumentTestStorageIsolationTest.php')) {
                $source = preg_replace('/\$forbidden = \[.*?\];/s', '', $source) ?? $source;
            }

            foreach ($forbidden as $needle) {
                $this->assertStringNotContainsString($needle, $source, basename($file)." must not contain '{$needle}'.");
            }
        }
    }

    public function test_the_inventory_command_and_the_inspector_contain_no_write_operations(): void
    {
        $write = ['->delete(', '->put(', '->save(', '->update(', '->create(', '->insert(', '->move(', '->copy(', '->write(', '->upload', 'file_put_contents', 'unlink(', 'rename(', 'mkdir(', 'touch(', 'rmdir(', 'DB::statement', 'DB::insert', 'DB::update', 'DB::delete', 'Storage::put', 'Storage::delete', 'forceFill', 'dispatch(', 'event('];

        foreach ([app_path('Console/Commands/InventoryEmployeeDocumentsCommand.php'), app_path('Support/Hr/Documents/DocumentFileInspector.php')] as $file) {
            $source = (string) file_get_contents($file);

            foreach ($write as $needle) {
                $this->assertStringNotContainsString($needle, $source, basename($file)." must stay read-only but contains '{$needle}'.");
            }
        }
    }
}
