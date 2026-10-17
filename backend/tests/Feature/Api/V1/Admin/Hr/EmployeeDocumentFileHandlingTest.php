<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Enums\DocumentFileState;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use App\Models\Permission;
use App\Support\Hr\Documents\DocumentFileInspector;
use Illuminate\Filesystem\FilesystemAdapter as LaravelDisk;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use League\Flysystem\FilesystemAdapter as FlysystemAdapter;
use League\Flysystem\FilesystemOperator;
use League\Flysystem\UnableToReadFile;
use Mockery;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\FakesDocumentDisk;
use Tests\TestCase;

/**
 * Document-download safety: a record whose file is gone must not end in a server error, a temporary storage problem must
 * not be reported as a lost file, nothing may be recreated or changed, and none of it may reveal to a caller who is not
 * allowed to see HR data whether a document exists. Disposable data and isolated fake disks only.
 */
class EmployeeDocumentFileHandlingTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private const MISSING_MESSAGE = 'This document is not available. Please ask the employee to provide it again and upload a new copy.';

    private const UNAVAILABLE_MESSAGE = 'Document storage is temporarily unavailable. Please try again in a few minutes.';

    private function superAdminToken(): string
    {
        return Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
    }

    private function tokenWithOnly(AdminPermission $permission): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('t')->plainTextToken;
    }

    private function noPermissionToken(): string
    {
        return Admin::factory()->create(['role' => AdminRole::Sales])->createToken('t')->plainTextToken;
    }

    private function makeEmployee(string $name = 'Sample Employee'): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => $name,
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function makeDocument(Employee $employee, ?string $path = null, ?string $content = null, string $fileName = 'passport-scan.pdf'): EmployeeDocument
    {
        $path ??= 'employees/'.$employee->id.'/documents/generatedname123.pdf';

        if ($content !== null) {
            Storage::disk('local')->put($path, $content);
        }

        return EmployeeDocument::query()->create([
            'employee_id' => $employee->id,
            'admin_id' => Admin::factory()->create()->id,
            'file_name' => $fileName,
            'file_type' => 'pdf',
            'file_size' => $content !== null ? strlen($content) : 1234,
            'file_path' => $path,
            'verification_status' => 'pending',
            'is_current' => true,
        ]);
    }

    private function downloadUrl(Employee $employee, EmployeeDocument $document): string
    {
        return "/api/v1/admin/hr/employees/{$employee->id}/documents/{$document->id}/download";
    }

    /** No path, file name, exception class or person detail may reach the user. */
    private function assertNothingInternalIsExposed(string $body, Employee $employee): void
    {
        foreach (['employees/', 'generatedname123', 'passport-scan', 'storage/', 'app/private', 'framework', 'Flysystem', 'Exception', 'vendor', '\\', $employee->full_name, $employee->phone, $employee->employee_number] as $needle) {
            $this->assertStringNotContainsString($needle, $body, "The response must not contain '{$needle}'.");
        }
    }

    /** Replaces the disk's Flysystem driver, so a storage fault can be scripted without touching any real folder. */
    private function useDriver(FilesystemOperator $operator): void
    {
        $root = rtrim(Storage::disk('local')->path(''), '/\\');

        Storage::set('local', new LaravelDisk($operator, Mockery::mock(FlysystemAdapter::class), ['driver' => 'local', 'root' => $root, 'throw' => false]));
    }

    /** An inspector whose open() answers with the given states in turn (the last one repeats). */
    private function inspectorOpening(DocumentFileState ...$states): void
    {
        $this->app->instance(DocumentFileInspector::class, new class($states) extends DocumentFileInspector
        {
            /** @param list<DocumentFileState> $states */
            public function __construct(private array $states)
            {
                parent::__construct('local');
            }

            public function open(?string $path): array|DocumentFileState
            {
                return count($this->states) > 1 ? array_shift($this->states) : $this->states[0];
            }
        });
    }

    public function test_an_existing_document_downloads_with_the_right_content_and_headers_under_the_production_disk_settings(): void
    {
        $this->fakeLocalDisk(['throw' => false]); // production's 'local' disk is configured with throw => false
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee, content: 'PDF-CONTENT-OK');

        $response = $this->withToken($this->superAdminToken())->get($this->downloadUrl($employee, $document));

        $response->assertOk();
        $this->assertSame('PDF-CONTENT-OK', $response->streamedContent());
        $this->assertStringContainsString('attachment', (string) $response->headers->get('Content-Disposition'));
        $this->assertStringContainsString('passport-scan.pdf', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame((string) strlen('PDF-CONTENT-OK'), $response->headers->get('Content-Length'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
        $this->assertNotSame('', (string) $response->headers->get('Content-Type'));
        // Personal documents must never be stored by a browser or a shared cache - the exact value, not just "private".
        $response->assertHeader('Cache-Control', 'no-store, private');
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function test_the_error_answers_for_a_document_stay_json_and_are_not_cacheable_either(): void
    {
        $this->fakeLocalDisk();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);

        $response = $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document));

        $response->assertStatus(404)->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING');
        $this->assertFalse($response->headers->hasCacheControlDirective('public'));
    }

    public function test_a_missing_file_answers_404_with_a_plain_message_and_changes_nothing(): void
    {
        $this->fakeLocalDisk(['throw' => false]);
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);
        $before = $document->fresh()->toArray();

        $response = $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document));

        $response->assertStatus(404)
            ->assertJsonPath('success', false)
            ->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING')
            ->assertJsonPath('message', self::MISSING_MESSAGE);
        $this->assertNothingInternalIsExposed($response->getContent(), $employee);

        // Nothing was recreated, repaired or edited, and the record is not claimed to be recovered.
        $this->assertSame($before, $document->fresh()->toArray());
        $this->assertSame([], Storage::disk('local')->allFiles());
        $this->assertStringNotContainsStringIgnoringCase('recovered', $response->getContent());
    }

    #[DataProvider('unusableReferences')]
    public function test_an_unusable_storage_reference_answers_404_and_never_reads_anything_else(string $reference): void
    {
        $this->fakeLocalDisk();
        Storage::disk('local')->put('secret-outside-documents.txt', 'TOP-SECRET');
        Storage::disk('local')->put('framework/sessions/abc', 'SESSION-DATA');
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee, $reference);

        $response = $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document));

        $response->assertStatus(404)->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING');
        $this->assertStringNotContainsString('TOP-SECRET', $response->getContent());
        $this->assertStringNotContainsString('SESSION-DATA', $response->getContent());
    }

    /** @return array<string, array{0: string}> */
    public static function unusableReferences(): array
    {
        return [
            'empty' => [''],
            'parent folder' => ['employees/1/documents/../../../secret-outside-documents.txt'],
            'absolute path' => ['/etc/hosts'],
            'drive path' => ['C:/Windows/win.ini'],
            'another folder of the same disk' => ['framework/sessions/abc'],
            'file in the disk root' => ['secret-outside-documents.txt'],
            'backslashes' => ['employees\\1\\documents\\x.pdf'],
            'nul byte' => ["employees/1/documents/x.pdf\0.txt"],
            'wrong layout' => ['employees/1/other/x.pdf'],
            'sub folder' => ['employees/1/documents/sub/x.pdf'],
            'url' => ['https://example.test/x.pdf'],
        ];
    }

    public function test_a_link_inside_the_documents_folder_that_points_outside_the_storage_is_never_served(): void
    {
        $this->fakeLocalDisk();
        $root = rtrim(Storage::disk('local')->path(''), '/\\');
        $outsideFolder = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fayadhowr_outside_'.bin2hex(random_bytes(4));
        mkdir($outsideFolder);
        $outsideFile = $outsideFolder.DIRECTORY_SEPARATOR.'outside.txt';
        file_put_contents($outsideFile, 'OUTSIDE-SECRET');
        $employee = $this->makeEmployee();
        mkdir($root.'/employees/'.$employee->id.'/documents', 0777, true);
        $link = $root.'/employees/'.$employee->id.'/documents/linked.txt';

        try {
            if (! @symlink($outsideFile, $link)) {
                $this->markTestSkipped('This machine does not allow creating symbolic links.');
            }

            $document = $this->makeDocument($employee, 'employees/'.$employee->id.'/documents/linked.txt');
            $response = $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document));

            $response->assertStatus(404)->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING');
            $this->assertStringNotContainsString('OUTSIDE-SECRET', $response->getContent());
        } finally {
            // Only the throw-away items this test created are removed: the link inside the isolated fake folder and the temp folder.
            if (is_link($link)) {
                @unlink($link);
            }
            @unlink($outsideFile);
            @rmdir($outsideFolder);
        }
    }

    public function test_the_containment_check_flags_a_location_that_resolves_outside_the_storage_root(): void
    {
        // Symbolic links cannot be created on every machine, so the same resolution logic is exercised with real temp folders
        // and a path that climbs out of the root. (The public entry points reject such a path before this check is reached.)
        $base = sys_get_temp_dir().DIRECTORY_SEPARATOR.'fayadhowr_root_'.bin2hex(random_bytes(4));
        $root = $base.DIRECTORY_SEPARATOR.'root';
        mkdir($root.DIRECTORY_SEPARATOR.'employees'.DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.'documents', 0777, true);
        file_put_contents($base.DIRECTORY_SEPARATOR.'outside.txt', 'OUTSIDE');
        file_put_contents($root.DIRECTORY_SEPARATOR.'employees'.DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'inside.pdf', 'INSIDE');
        config(['filesystems.disks.local.root' => $root]);
        $check = new \ReflectionMethod(DocumentFileInspector::class, 'resolvesOutsideTheStorage');

        try {
            $inspector = new DocumentFileInspector;
            $this->assertTrue($check->invoke($inspector, 'employees/1/documents/../../../../outside.txt'));
            $this->assertFalse($check->invoke($inspector, 'employees/1/documents/inside.pdf'));
            $this->assertFalse($check->invoke($inspector, 'employees/1/documents/does-not-exist.pdf'), 'A file that cannot be resolved is left to the normal existence check.');
        } finally {
            // Only the throw-away temp items this test created.
            @unlink($root.DIRECTORY_SEPARATOR.'employees'.DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.'documents'.DIRECTORY_SEPARATOR.'inside.pdf');
            @unlink($base.DIRECTORY_SEPARATOR.'outside.txt');
            @rmdir($root.DIRECTORY_SEPARATOR.'employees'.DIRECTORY_SEPARATOR.'1'.DIRECTORY_SEPARATOR.'documents');
            @rmdir($root.DIRECTORY_SEPARATOR.'employees'.DIRECTORY_SEPARATOR.'1');
            @rmdir($root.DIRECTORY_SEPARATOR.'employees');
            @rmdir($root);
            @rmdir($base);
        }
    }

    public function test_unavailable_storage_answers_503_and_does_not_claim_the_file_is_missing_and_does_not_recreate_the_root(): void
    {
        $this->fakeLocalDisk();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee, content: 'PDF-CONTENT-OK');
        // The storage root disappears (for example a volume that did not mount): nothing can be said about any file.
        $this->makeStorageUnreachable();
        $unreachableRoot = (string) config('filesystems.disks.local.root');

        $response = $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document));

        $response->assertStatus(503)
            ->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE')
            ->assertJsonPath('message', self::UNAVAILABLE_MESSAGE)
            ->assertHeader('Retry-After', '60');
        $this->assertStringNotContainsStringIgnoringCase('missing', $response->getContent());
        $this->assertStringNotContainsStringIgnoringCase('not available', $response->getContent());
        $this->assertNothingInternalIsExposed($response->getContent(), $employee);
        $this->assertDirectoryDoesNotExist($unreachableRoot, 'Looking for a document must never recreate a missing storage root.');
    }

    public function test_a_file_that_cannot_be_opened_answers_503(): void
    {
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);
        $this->inspectorOpening(DocumentFileState::Unreadable);

        $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document))
            ->assertStatus(503)
            ->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');
    }

    public function test_a_file_that_vanishes_between_the_check_and_the_read_answers_404_not_500(): void
    {
        $this->fakeLocalDisk(['throw' => false]);
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);
        $operator = Mockery::mock(FilesystemOperator::class);
        $operator->shouldReceive('fileExists')->andReturn(true, false); // there when checked, gone when read and re-checked
        $operator->shouldReceive('readStream')->andThrow(UnableToReadFile::fromLocation('employees/x'));
        $this->useDriver($operator);

        $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document))
            ->assertStatus(404)
            ->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING');
    }

    public function test_a_read_failure_that_cannot_be_explained_is_a_storage_problem_not_a_lost_file(): void
    {
        $this->fakeLocalDisk(['throw' => false]);
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);
        $operator = Mockery::mock(FilesystemOperator::class);
        $operator->shouldReceive('fileExists')->andReturn(true); // still there, yet it cannot be read
        $operator->shouldReceive('readStream')->andThrow(UnableToReadFile::fromLocation('employees/x', 'Permission denied'));
        $this->useDriver($operator);

        $response = $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document));

        $response->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');
        $this->assertStringNotContainsString('Permission denied', $response->getContent());
    }

    public function test_a_failing_existence_check_is_a_storage_problem_not_a_lost_file(): void
    {
        $this->fakeLocalDisk(['throw' => false]);
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);
        $operator = Mockery::mock(FilesystemOperator::class);
        $operator->shouldReceive('fileExists')->andThrow(new \RuntimeException('network share went away'));
        $this->useDriver($operator);

        $response = $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document));

        $response->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');
        $this->assertStringNotContainsString('network share', $response->getContent());
    }

    /** @return array<string, string> every way of asking for a document, existing or not */
    private function everyKindOfRequest(): array
    {
        $a = $this->makeEmployee('Employee A');
        $b = $this->makeEmployee('Employee B');
        $present = $this->makeDocument($a, content: 'X');
        $missing = $this->makeDocument($a, "employees/{$a->id}/documents/other.pdf");
        $unusable = $this->makeDocument($a, '../../etc/passwd');
        $foreign = $this->makeDocument($b, content: 'Y');
        $trashed = $this->makeEmployee('Employee Gone');
        $trashedDocument = $this->makeDocument($trashed, content: 'Z');
        $trashed->delete();
        $base = '/api/v1/admin/hr/employees';

        return [
            'present file' => "{$base}/{$a->id}/documents/{$present->id}/download",
            'missing file' => "{$base}/{$a->id}/documents/{$missing->id}/download",
            'unusable reference' => "{$base}/{$a->id}/documents/{$unusable->id}/download",
            'document of another employee' => "{$base}/{$a->id}/documents/{$foreign->id}/download",
            'document that does not exist' => "{$base}/{$a->id}/documents/987654/download",
            'employee that does not exist' => "{$base}/987654/documents/{$present->id}/download",
            'neither exists' => "{$base}/987654/documents/987654/download",
            'employee that was removed' => "{$base}/{$trashed->id}/documents/{$trashedDocument->id}/download",
        ];
    }

    public function test_a_caller_without_hr_permission_gets_one_identical_refusal_for_every_kind_of_request(): void
    {
        $this->fakeLocalDisk();
        $token = $this->noPermissionToken();

        $answers = collect($this->everyKindOfRequest())->map(function (string $url, string $kind) use ($token): array {
            $response = $this->withToken($token)->getJson($url);
            $this->assertSame(403, $response->status(), "A caller without permission must get 403 for: {$kind} (got {$response->status()}).");

            return [$response->status(), $response->json('error_code'), $response->json('message'), array_keys($response->json())];
        });

        $this->assertCount(1, $answers->unique(), 'The refusal must not differ between existing and non-existing documents.');
    }

    public function test_the_refusal_stays_identical_even_when_the_storage_is_down(): void
    {
        $this->fakeLocalDisk();
        $token = $this->noPermissionToken();
        $requests = $this->everyKindOfRequest();
        $this->makeStorageUnreachable();

        $answers = collect($requests)->map(fn (string $url): array => [$this->withToken($token)->getJson($url)->status()]);

        $this->assertSame([[403]], $answers->unique()->values()->all());
    }

    public function test_an_unauthenticated_caller_gets_401_for_every_kind_of_request(): void
    {
        $this->fakeLocalDisk();

        foreach ($this->everyKindOfRequest() as $kind => $url) {
            $this->getJson($url)->assertStatus(401, "Unauthenticated callers must get 401 for: {$kind}");
        }
    }

    public function test_a_caller_with_a_write_only_permission_cannot_download(): void
    {
        $this->fakeLocalDisk();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee, content: 'X');

        // hr.manage alone is not hr.view: the route's own permission decides, exactly as before.
        $this->withToken($this->tokenWithOnly(AdminPermission::HrManage))->getJson($this->downloadUrl($employee, $document))->assertStatus(403);
    }

    public function test_an_authorized_caller_gets_one_not_found_answer_for_everything_that_is_not_theirs_to_find(): void
    {
        $this->fakeLocalDisk();
        $token = $this->tokenWithOnly(AdminPermission::HrView);
        $requests = $this->everyKindOfRequest();

        $notFound = collect(['document of another employee', 'document that does not exist', 'employee that does not exist', 'neither exists', 'employee that was removed'])
            ->map(function (string $kind) use ($requests, $token): array {
                $response = $this->withToken($token)->getJson($requests[$kind]);
                $this->assertSame(404, $response->status(), "{$kind} must be 404.");

                return [$response->json('error_code'), $response->json('message')];
            });

        $this->assertCount(1, $notFound->unique());
        $this->assertSame('EMPLOYEE_DOCUMENT_NOT_FOUND', $notFound->first()[0]);

        // For a document that does belong to the employee, the file-state answers stay accurate and distinct.
        $this->withToken($token)->get($requests['present file'])->assertOk();
        $this->withToken($token)->getJson($requests['missing file'])->assertStatus(404)->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING');
        $this->withToken($token)->getJson($requests['unusable reference'])->assertStatus(404)->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING');
    }

    public function test_the_server_log_names_the_problem_by_internal_id_only(): void
    {
        $this->fakeLocalDisk();
        Log::spy();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);

        $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document))->assertStatus(404);

        Log::shouldHaveReceived('warning')->once()->withArgs(fn (string $message, array $context): bool => $context === ['document_id' => $document->id, 'employee_id' => $employee->id, 'reason' => 'missing']
            && ! str_contains($message, 'employees/'));
    }

    public function test_a_storage_outage_is_logged_as_an_error_with_the_reason(): void
    {
        $this->fakeLocalDisk();
        Log::spy();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee, content: 'X');
        $this->makeStorageUnreachable();

        $this->withToken($this->superAdminToken())->getJson($this->downloadUrl($employee, $document))->assertStatus(503);

        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message, array $context): bool => $context === ['document_id' => $document->id, 'employee_id' => $employee->id, 'reason' => 'storage_unavailable']);
    }

    public function test_a_record_whose_file_is_gone_can_still_be_deleted(): void
    {
        $this->fakeLocalDisk();
        $employee = $this->makeEmployee();
        $document = $this->makeDocument($employee);

        $this->withToken($this->superAdminToken())
            ->deleteJson("/api/v1/admin/hr/employees/{$employee->id}/documents/{$document->id}")
            ->assertOk();

        $this->assertDatabaseMissing('employee_documents', ['id' => $document->id]);
    }

    public function test_the_inspector_tells_every_state_apart(): void
    {
        $this->fakeLocalDisk();
        Storage::disk('local')->put('employees/7/documents/a.pdf', '12345');
        $inspector = new DocumentFileInspector;

        $this->assertSame(DocumentFileState::Verified, $inspector->inspect('employees/7/documents/a.pdf', 5));
        $this->assertSame(DocumentFileState::Verified, $inspector->inspect('employees/7/documents/a.pdf'));
        $this->assertSame(DocumentFileState::SizeMismatch, $inspector->inspect('employees/7/documents/a.pdf', 99));
        $this->assertSame(DocumentFileState::Missing, $inspector->inspect('employees/7/documents/none.pdf', 5));
        $this->assertSame(DocumentFileState::InvalidReference, $inspector->inspect('../x'));
        $this->assertSame(DocumentFileState::InvalidReference, $inspector->inspect(null));

        $opened = $inspector->open('employees/7/documents/a.pdf');
        $this->assertIsArray($opened);
        $this->assertSame('12345', stream_get_contents($opened['stream']));
        $this->assertSame(5, $opened['size']);
        fclose($opened['stream']);
        $this->assertSame(DocumentFileState::Missing, $inspector->open('employees/7/documents/none.pdf'));
        $this->assertSame(DocumentFileState::InvalidReference, $inspector->open('/etc/hosts'));

        $this->makeStorageUnreachable();
        $this->assertSame(DocumentFileState::StorageUnavailable, $inspector->inspect('employees/7/documents/a.pdf', 5));
        $this->assertSame(DocumentFileState::StorageUnavailable, $inspector->open('employees/7/documents/a.pdf'));
        $this->assertSame(DocumentFileState::StorageUnavailable, (new DocumentFileInspector('no-such-disk'))->inspect('employees/7/documents/a.pdf'));
    }
}
