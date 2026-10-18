<?php

namespace Tests\Feature\Hr;

use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\FakeS3Server;
use Tests\Support\FakesDocumentDisk;
use Tests\Support\InMemoryObjectStore;
use Tests\TestCase;

/**
 * Uploads and downloads of employee documents against the new storage layer: the document records the disk and checksum it was
 * written with, older documents stay reachable, a write that cannot be verified saves nothing, a damaged file is never served,
 * and storage problems answer plainly. Isolated fake disks and an in-memory store only - no bucket, volume or credential.
 */
class DocumentStorageTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private string $token;

    private Employee $employee;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
        $this->employee = Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => 'Storage Probe',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    private function upload(string $content = 'PDF-BYTES-ONE', ?int $categoryId = null, string $name = 'scan.pdf')
    {
        return $this->withToken($this->token)->post("/api/v1/admin/hr/employees/{$this->employee->id}/documents", array_filter([
            'file' => UploadedFile::fake()->createWithContent($name, $content),
            'employee_document_category_id' => $categoryId,
        ]), ['Accept' => 'application/json']);
    }

    private function download(EmployeeDocument|int $document)
    {
        $id = $document instanceof EmployeeDocument ? $document->id : $document;

        return $this->withToken($this->token)->get("/api/v1/admin/hr/employees/{$this->employee->id}/documents/{$id}/download");
    }

    public function test_the_defaults_keep_todays_behaviour(): void
    {
        $this->assertSame('local', config('filesystems.hr_documents_disk'));
        $this->assertNull(config('filesystems.hr_documents_mirror_disk'));
        $this->assertTrue((bool) config('filesystems.hr_documents_verify_readback'));
        $this->assertTrue((bool) config('filesystems.hr_documents_verify_on_download'));
        // The R2 disk exists only as configuration: private, bucket-scoped by environment, and no public url.
        $this->assertSame('s3', config('filesystems.disks.r2_documents.driver'));
        $this->assertSame('private', config('filesystems.disks.r2_documents.visibility'));
        $this->assertNull(config('filesystems.disks.r2_documents.url'));
        $this->assertNull(config('filesystems.disks.r2_documents.key'), 'No credential may ever be committed to configuration.');
    }

    public function test_an_upload_records_the_disk_size_and_checksum_and_downloads_back_identically(): void
    {
        $this->fakeLocalDisk();

        $response = $this->upload('PDF-BYTES-ONE')->assertStatus(201);
        $document = EmployeeDocument::query()->findOrFail($response->json('data.id'));

        $this->assertSame('local', $document->disk);
        $this->assertSame(hash('sha256', 'PDF-BYTES-ONE'), $document->sha256);
        $this->assertSame(strlen('PDF-BYTES-ONE'), (int) $document->file_size);
        $this->assertSame('PDF-BYTES-ONE', Storage::disk('local')->get($document->file_path));
        $this->assertStringStartsWith("employees/{$this->employee->id}/documents/", $document->file_path);

        $download = $this->download($document)->assertOk();
        $this->assertSame('PDF-BYTES-ONE', $download->streamedContent());
        $this->assertSame('no-store, private', $download->headers->get('Cache-Control'));
        // The API never reveals where a file is kept.
        $this->assertStringNotContainsString($document->file_path, (string) $response->getContent());
        $this->assertArrayNotHasKey('disk', $response->json('data'));
        $this->assertArrayNotHasKey('sha256', $response->json('data'));
    }

    public function test_new_uploads_go_to_the_configured_disk_and_older_documents_stay_reachable_on_theirs(): void
    {
        $this->fakeLocalDisk();
        $this->fakeDisk('hr_primary');

        $old = EmployeeDocument::query()->findOrFail($this->upload('OLD-ON-LOCAL')->json('data.id'));

        config(['filesystems.hr_documents_disk' => 'hr_primary']);
        $new = EmployeeDocument::query()->findOrFail($this->upload('NEW-ON-PRIMARY')->json('data.id'));

        $this->assertSame('local', $old->disk);
        $this->assertSame('hr_primary', $new->disk);
        Storage::disk('hr_primary')->assertExists($new->file_path);
        Storage::disk('local')->assertMissing($new->file_path);
        Storage::disk('local')->assertExists($old->file_path);
        Storage::disk('hr_primary')->assertMissing($old->file_path);

        $this->assertSame('OLD-ON-LOCAL', $this->download($old)->assertOk()->streamedContent());
        $this->assertSame('NEW-ON-PRIMARY', $this->download($new)->assertOk()->streamedContent());
    }

    public function test_replacing_a_document_keeps_the_old_one_and_its_file(): void
    {
        $this->fakeLocalDisk();
        $category = EmployeeDocumentCategory::query()->where('name', 'Identity Documents')->firstOrFail();

        $first = EmployeeDocument::query()->findOrFail($this->upload('FIRST', $category->id)->json('data.id'));
        $second = EmployeeDocument::query()->findOrFail($this->upload('SECOND', $category->id)->json('data.id'));

        $this->assertFalse($first->fresh()->is_current);
        $this->assertSame($second->id, $first->fresh()->superseded_by_document_id);
        Storage::disk('local')->assertExists($first->file_path);
        Storage::disk('local')->assertExists($second->file_path);
        $this->assertNotSame($first->file_path, $second->file_path);
    }

    public function test_deleting_a_document_removes_only_the_file_on_its_own_disk_never_a_mirror_copy(): void
    {
        $this->fakeLocalDisk();
        $this->fakeDisk('hr_mirror');
        $document = EmployeeDocument::query()->findOrFail($this->upload('KEEP-A-COPY')->json('data.id'));
        Storage::disk('hr_mirror')->put($document->file_path, 'KEEP-A-COPY'); // a recovery copy under the same path

        $this->withToken($this->token)->deleteJson("/api/v1/admin/hr/employees/{$this->employee->id}/documents/{$document->id}")->assertOk();

        $this->assertDatabaseMissing('employee_documents', ['id' => $document->id]);
        Storage::disk('local')->assertMissing($document->file_path);
        Storage::disk('hr_mirror')->assertExists($document->file_path);
    }

    public function test_deleting_a_document_that_lives_on_a_configured_disk_removes_that_file(): void
    {
        $this->fakeLocalDisk();
        $this->fakeDisk('hr_primary');
        config(['filesystems.hr_documents_disk' => 'hr_primary']);
        $document = EmployeeDocument::query()->findOrFail($this->upload('ON-THE-PRIMARY-DISK')->json('data.id'));
        Storage::disk('hr_primary')->assertExists($document->file_path);

        $this->withToken($this->token)->deleteJson("/api/v1/admin/hr/employees/{$this->employee->id}/documents/{$document->id}")->assertOk();

        Storage::disk('hr_primary')->assertMissing($document->file_path);
        $this->assertDatabaseMissing('employee_documents', ['id' => $document->id]);
    }

    public function test_a_file_whose_contents_changed_is_never_served_and_the_user_is_told_plainly(): void
    {
        $this->fakeLocalDisk();
        Log::spy();
        $document = EmployeeDocument::query()->findOrFail($this->upload('ORIGINAL-CONTENT')->json('data.id'));
        Storage::disk('local')->put($document->file_path, 'TAMPERED-CONTENT!'); // damaged or altered after upload

        $response = $this->download($document);

        $response->assertStatus(502);
        $response->assertJsonPath('error_code', 'DOCUMENT_INTEGRITY_FAILED');
        $this->assertStringNotContainsString('TAMPERED', (string) $response->getContent());
        $this->assertStringNotContainsString($document->file_path, (string) $response->getContent());
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $c): bool => $c === ['document_id' => $document->id, 'employee_id' => $this->employee->id, 'reason' => 'checksum_mismatch']);
    }

    public function test_a_same_size_substitution_is_also_caught(): void
    {
        $this->fakeLocalDisk();
        $document = EmployeeDocument::query()->findOrFail($this->upload('AAAAAAAAAA')->json('data.id'));
        Storage::disk('local')->put($document->file_path, 'BBBBBBBBBB'); // same length, different bytes

        $this->download($document)->assertStatus(502)->assertJsonPath('error_code', 'DOCUMENT_INTEGRITY_FAILED');
    }

    public function test_the_download_check_can_be_switched_off_and_documents_without_a_checksum_are_served_as_before(): void
    {
        $this->fakeLocalDisk();
        $document = EmployeeDocument::query()->findOrFail($this->upload('ORIGINAL')->json('data.id'));
        Storage::disk('local')->put($document->file_path, 'CHANGED!!');

        config(['filesystems.hr_documents_verify_on_download' => false]);
        $this->assertSame('CHANGED!!', $this->download($document)->assertOk()->streamedContent());

        // A document uploaded before checksums existed has none recorded: nothing to compare, so it is served (and flagged by the inventory instead).
        config(['filesystems.hr_documents_verify_on_download' => true]);
        $document->forceFill(['sha256' => null])->save();
        $this->assertSame('CHANGED!!', $this->download($document)->assertOk()->streamedContent());
    }

    public function test_an_upload_to_unavailable_storage_is_refused_and_saves_nothing(): void
    {
        $this->fakeLocalDisk();
        $this->fakeDisk('hr_primary');
        config(['filesystems.hr_documents_disk' => 'hr_primary']);
        $this->makeDiskUnreachable('hr_primary');
        $root = (string) config('filesystems.disks.hr_primary.root');

        $response = $this->upload('WILL-NOT-BE-SAVED');

        $response->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE')->assertHeader('Retry-After', '60');
        $this->assertStringContainsString('Nothing was saved', (string) $response->json('message'));
        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertDirectoryDoesNotExist($root);
    }

    public function test_an_upload_to_a_disk_that_cannot_be_built_is_refused_without_leaking_its_settings(): void
    {
        $this->fakeLocalDisk();
        Log::spy();
        // A real s3 disk whose endpoint cannot be reached (in-process, so no network call is ever attempted).
        $unreachable = new FakeS3Server('secret-bucket-name');
        $unreachable->down();
        config([
            'filesystems.disks.broken_s3' => array_merge($unreachable->diskConfig(), ['key' => 'AKIA-SECRET', 'secret' => 'shh', 'endpoint' => 'https://secret-endpoint.invalid']),
            'filesystems.hr_documents_disk' => 'broken_s3',
        ]);

        $response = $this->upload('X');

        $response->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');
        foreach (['secret-bucket-name', 'AKIA-SECRET', 'shh', 'secret-endpoint', 'Class', 'Aws', 'vendor'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $response->getContent());
        }
        $this->assertDatabaseCount('employee_documents', 0);
        Log::shouldHaveReceived('error')->once()->withArgs(fn (string $m, array $c): bool => ($c['reason'] ?? null) === 'write_failed' && ! str_contains(json_encode($c), 'secret'));
    }

    public function test_a_write_cut_short_leaves_no_object_and_no_record(): void
    {
        $this->fakeLocalDisk();
        $store = $this->useObjectStore('hr_object', new InMemoryObjectStore);
        config(['filesystems.hr_documents_disk' => 'hr_object']);
        $store->failNextWrites = 1;

        $this->upload('FULL-CONTENT-OF-THE-FILE')->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');

        $this->assertSame([], $store->objects, 'The partial object the failed write left behind must be removed.');
        $this->assertCount(1, $store->deleted, 'Only the object this request created was removed.');
        $this->assertDatabaseCount('employee_documents', 0);
    }

    public function test_a_write_that_stores_the_wrong_bytes_is_caught_by_the_read_back_and_saves_nothing(): void
    {
        $this->fakeLocalDisk();
        $store = $this->useObjectStore('hr_object', new InMemoryObjectStore);
        config(['filesystems.hr_documents_disk' => 'hr_object']);
        $store->corruptNextWrites = 1;

        $this->upload('GOOD-BYTES')->assertStatus(503);

        $this->assertSame([], $store->objects);
        $this->assertDatabaseCount('employee_documents', 0);

        // Once the store behaves, the very same upload succeeds and is recorded against that disk.
        $document = EmployeeDocument::query()->findOrFail($this->upload('GOOD-BYTES')->assertStatus(201)->json('data.id'));
        $this->assertSame('hr_object', $document->disk);
        $this->assertSame('GOOD-BYTES', $store->objects[$document->file_path]);
    }

    public function test_a_write_that_silently_stores_too_few_bytes_is_caught_by_the_size_check_and_saves_nothing(): void
    {
        $this->fakeLocalDisk();
        $store = $this->useObjectStore('hr_object', new InMemoryObjectStore);
        config(['filesystems.hr_documents_disk' => 'hr_object', 'filesystems.hr_documents_verify_readback' => false]); // only the size check can catch this
        $store->truncateNextWrites = 1;

        $this->upload('A-LONGER-DOCUMENT-BODY')->assertStatus(503);

        $this->assertSame([], $store->objects);
        $this->assertDatabaseCount('employee_documents', 0);
    }

    public function test_a_full_storage_volume_is_reported_as_unavailable_and_saves_nothing(): void
    {
        $this->fakeLocalDisk();
        $store = $this->useObjectStore('hr_object', new InMemoryObjectStore);
        config(['filesystems.hr_documents_disk' => 'hr_object']);
        $store->full = true;

        $this->upload('NO-ROOM')->assertStatus(503);

        $this->assertSame([], $store->objects);
        $this->assertDatabaseCount('employee_documents', 0);
    }

    public function test_when_saving_the_record_fails_after_the_file_was_written_the_file_is_removed_again(): void
    {
        $this->fakeLocalDisk();
        $this->fakeDisk('hr_primary');
        config(['filesystems.hr_documents_disk' => 'hr_primary']);
        Event::listen(AuditEvent::class, function (): void {
            throw new RuntimeException('simulated failure after the record was created');
        });

        $this->upload('ORPHAN-IF-LEFT')->assertStatus(500);

        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertSame([], Storage::disk('hr_primary')->allFiles(), 'No stray file may remain when the record was not saved.');
    }

    public function test_a_record_on_an_unknown_disk_or_with_a_missing_object_answers_plainly(): void
    {
        $this->fakeLocalDisk();
        $this->fakeDisk('hr_primary');
        $make = fn (string $disk, string $path) => EmployeeDocument::query()->create([
            'employee_id' => $this->employee->id, 'admin_id' => Admin::factory()->create()->id, 'file_name' => 'x.pdf', 'file_type' => 'pdf',
            'file_size' => 3, 'file_path' => $path, 'disk' => $disk, 'sha256' => hash('sha256', 'abc'), 'verification_status' => 'pending', 'is_current' => true,
        ]);

        $this->download($make('no_such_disk', "employees/{$this->employee->id}/documents/a.pdf"))->assertStatus(503);
        $this->download($make('hr_primary', "employees/{$this->employee->id}/documents/gone.pdf"))->assertStatus(404);
        // A traversal-style reference is refused on any disk without reading anything.
        Storage::disk('hr_primary')->put('secret.txt', 'SECRET');
        $response = $this->download($make('hr_primary', 'employees/1/documents/../../secret.txt'))->assertStatus(404);
        $this->assertStringNotContainsString('SECRET', (string) $response->getContent());
    }

    public function test_a_document_cannot_be_downloaded_through_another_employees_url(): void
    {
        $this->fakeLocalDisk();
        $document = EmployeeDocument::query()->findOrFail($this->upload('PRIVATE')->json('data.id'));
        $other = Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'), 'full_name' => 'Other', 'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id, 'status' => 'active', 'application_date' => now()->toDateString(),
        ]);

        $this->withToken($this->token)->getJson("/api/v1/admin/hr/employees/{$other->id}/documents/{$document->id}/download")
            ->assertStatus(404)->assertJsonPath('error_code', 'EMPLOYEE_DOCUMENT_NOT_FOUND');
    }

    public function test_the_inventory_checks_each_record_on_its_own_disk_and_can_verify_checksums(): void
    {
        $this->fakeLocalDisk();
        $this->fakeDisk('hr_primary');
        $local = EmployeeDocument::query()->findOrFail($this->upload('ON-LOCAL')->json('data.id'));
        config(['filesystems.hr_documents_disk' => 'hr_primary']);
        $primary = EmployeeDocument::query()->findOrFail($this->upload('ON-PRIMARY')->json('data.id'));
        Storage::disk('hr_primary')->put($primary->file_path, 'ON-PRIMARX'); // same length, different bytes

        Artisan::call('hr:documents-inventory --json --skip-orphans');
        $quick = json_decode(Artisan::output(), true);
        Artisan::call('hr:documents-inventory --json --skip-orphans --checksums --details');
        $deep = json_decode(Artisan::output(), true);

        $this->assertSame(2, $quick['summary']['total_records']);
        $this->assertSame(2, $quick['summary']['verified'], 'Without --checksums only existence and size are compared.');
        $this->assertSame(1, $deep['summary']['checksum_mismatch']);
        $this->assertSame(1, $deep['summary']['verified']);
        $this->assertSame([['document_id' => $primary->id, 'employee_id' => $this->employee->id, 'disk' => 'hr_primary', 'current' => true, 'uploaded_on' => $primary->created_at->toDateString(), 'problem' => 'checksum_mismatch']], $deep['problem_records']);
        $this->assertSame($local->id, EmployeeDocument::query()->where('disk', 'local')->value('id'));
    }
}
