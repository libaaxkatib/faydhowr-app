<?php

namespace Tests\Feature\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeS3Server;
use Tests\Support\FakesDocumentDisk;
use Tests\TestCase;

/**
 * The employee-document flow through the REAL S3 chain (Laravel s3 disk, Flysystem adapter, AWS SDK) against an in-process fake
 * S3 endpoint. Fake credentials, no network, no bucket. This proves our code and the SDK's behaviour, not Cloudflare R2's:
 * anything R2-specific stays on the approval-gated rehearsal checklist.
 */
class DocumentS3AdapterTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private string $token;

    private Employee $employee;

    private FakeS3Server $s3;

    protected function setUp(): void
    {
        parent::setUp();

        $this->token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
        $this->employee = $this->makeEmployee();
        $this->fakeLocalDisk();
        $this->useFakeS3();
    }

    private function makeEmployee(): Employee
    {
        return Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => 'S3 Probe',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    /**
     * Points the real `r2_documents` disk definition at a fresh fake S3 endpoint (a new, empty bucket).
     *
     * @param  array<string, mixed>  $extra  extra disk settings (for example the checksum options under test)
     */
    private function useFakeS3(array $extra = []): FakeS3Server
    {
        $this->s3 = new FakeS3Server;
        config([
            'filesystems.disks.r2_documents' => array_merge((array) config('filesystems.disks.r2_documents'), $this->s3->diskConfig(), $extra),
            'filesystems.hr_documents_disk' => 'r2_documents',
        ]);
        Storage::purge('r2_documents');

        return $this->s3;
    }

    private function upload(string $content = 'PDF-BYTES-ONE', ?Employee $employee = null)
    {
        $employee ??= $this->employee;

        return $this->withToken($this->token)->post("/api/v1/admin/hr/employees/{$employee->id}/documents", [
            'file' => UploadedFile::fake()->createWithContent('scan.pdf', $content),
        ], ['Accept' => 'application/json']);
    }

    private function download(EmployeeDocument $document, ?int $employeeId = null)
    {
        return $this->withToken($this->token)->get('/api/v1/admin/hr/employees/'.($employeeId ?? $document->employee_id)."/documents/{$document->id}/download");
    }

    private function uploaded(string $content = 'PDF-BYTES-ONE', ?Employee $employee = null): EmployeeDocument
    {
        return EmployeeDocument::query()->findOrFail($this->upload($content, $employee)->assertStatus(201)->json('data.id'));
    }

    // ---- what the SDK sends -------------------------------------------------------------------------------------------

    public function test_the_default_sdk_settings_send_a_private_acl_and_a_crc32_checksum_header(): void
    {
        // Recorded as a fact about the SDK (3.401.x) because Cloudflare R2's acceptance of these headers is not proven locally.
        $this->useFakeS3(['request_checksum_calculation' => 'when_supported', 'response_checksum_validation' => 'when_supported']);
        $this->uploaded('HEADER-PROBE');

        $put = $this->s3->requestsOf('PUT')[0]['headers'];
        $this->assertSame('private', $put['x-amz-acl']);
        $this->assertArrayHasKey('x-amz-checksum-crc32', $put);
        $this->assertSame('[redacted]', $put['authorization'], 'Every request is signed.');
        $this->assertArrayNotHasKey('x-amz-meta-employee', $put);
    }

    public function test_the_shipped_r2_disk_asks_the_sdk_to_send_checksum_headers_only_when_required(): void
    {
        $this->assertSame('when_required', config('filesystems.disks.r2_documents.request_checksum_calculation'));
        $this->assertSame('when_required', config('filesystems.disks.r2_documents.response_checksum_validation'));
        $this->assertTrue((bool) config('filesystems.disks.r2_documents.use_path_style_endpoint'));
        $this->assertNull(config('filesystems.disks.r2_documents.url'), 'No public URL may ever be configured.');
        $this->assertSame('private', config('filesystems.disks.r2_documents.visibility'));
    }

    public function test_with_when_required_no_crc32_header_is_sent_and_our_own_sha256_readback_still_verifies(): void
    {
        $this->useFakeS3(['request_checksum_calculation' => 'when_required', 'response_checksum_validation' => 'when_required']);

        $document = $this->uploaded('NO-CRC-PROBE');

        $put = $this->s3->requestsOf('PUT')[0]['headers'];
        $this->assertArrayNotHasKey('x-amz-checksum-crc32', $put);
        $this->assertSame(hash('sha256', 'NO-CRC-PROBE'), $document->sha256);
        $this->assertSame('NO-CRC-PROBE', $this->download($document)->assertOk()->streamedContent());
    }

    // ---- the normal path ----------------------------------------------------------------------------------------------

    public function test_an_upload_is_written_to_the_bucket_verified_and_downloaded_back_identically(): void
    {
        $document = $this->uploaded('PDF-BYTES-ONE');

        $this->assertSame('r2_documents', $document->disk);
        $this->assertSame(hash('sha256', 'PDF-BYTES-ONE'), $document->sha256);
        $this->assertSame(strlen('PDF-BYTES-ONE'), (int) $document->file_size);
        $this->assertSame('PDF-BYTES-ONE', $this->s3->objects[$document->file_path]['bytes']);
        $this->assertStringStartsWith("employees/{$this->employee->id}/documents/", $document->file_path);
        $this->assertSame(['HEAD', 'PUT', 'HEAD', 'GET'], array_column($this->s3->requests, 'method'), 'exists check, write, size check, read-back');

        $this->s3->requests = [];
        $response = $this->download($document)->assertOk();
        $this->assertSame('PDF-BYTES-ONE', $response->streamedContent());
        $this->assertSame('no-store, private', $response->headers->get('Cache-Control'));
        // Nothing the application sends to a browser ever points at the bucket.
        $this->assertStringNotContainsString('fake-r2', json_encode($response->headers->all()));
        // Nothing local was written.
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_a_second_version_is_a_new_object_and_the_first_one_is_not_touched(): void
    {
        $first = $this->uploaded('VERSION-ONE');
        $second = $this->uploaded('VERSION-TWO');

        $this->assertNotSame($first->file_path, $second->file_path);
        $this->assertSame('VERSION-ONE', $this->s3->objects[$first->file_path]['bytes']);
        $this->assertSame('VERSION-TWO', $this->s3->objects[$second->file_path]['bytes']);
        $this->assertSame([], $this->s3->deleted);
    }

    public function test_deleting_a_document_removes_only_its_own_object_from_the_bucket(): void
    {
        $keep = $this->uploaded('KEEP-ME');
        $remove = $this->uploaded('REMOVE-ME');

        $this->withToken($this->token)->deleteJson("/api/v1/admin/hr/employees/{$this->employee->id}/documents/{$remove->id}")->assertSuccessful();

        $this->assertSame([$remove->file_path], $this->s3->deleted);
        $this->assertArrayNotHasKey($remove->file_path, $this->s3->objects);
        $this->assertSame('KEEP-ME', $this->s3->objects[$keep->file_path]['bytes']);
    }

    // ---- writes that go wrong -----------------------------------------------------------------------------------------

    public function test_a_write_the_bucket_refuses_saves_nothing_and_leaks_nothing(): void
    {
        $this->s3->failNextWrites();

        $response = $this->upload('REFUSED');

        $response->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');
        foreach (['FAKEACCESSKEYID', 'fake-secret', 'fake-r2', 'fake-documents', 'AccessDenied', 'Aws', 'vendor'] as $needle) {
            $this->assertStringNotContainsString($needle, (string) $response->getContent());
        }
        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertSame([], $this->s3->objects);
    }

    public function test_silently_corrupted_bytes_of_the_same_length_are_caught_removed_and_never_saved(): void
    {
        $this->s3->corruptNextWrites();

        $this->upload('SAME-LENGTH-DAMAGE')->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');

        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertSame([], $this->s3->objects, 'The object this request created is removed again.');
        $this->assertCount(1, $this->s3->deleted);
    }

    public function test_a_truncated_object_is_caught_removed_and_never_saved(): void
    {
        $this->s3->truncateNextWrites();

        $this->upload('A-LONGER-DOCUMENT-BODY')->assertStatus(503);

        $this->assertDatabaseCount('employee_documents', 0);
        $this->assertSame([], $this->s3->objects);
    }

    public function test_an_unreachable_endpoint_at_upload_saves_nothing(): void
    {
        $this->s3->down();

        $this->upload('NO-ROUTE')->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');

        $this->assertDatabaseCount('employee_documents', 0);
    }

    // ---- downloads that go wrong --------------------------------------------------------------------------------------

    public function test_an_unreachable_endpoint_at_download_answers_503_and_keeps_the_record(): void
    {
        $document = $this->uploaded('STILL-HERE');
        $this->s3->down();

        $this->download($document)->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');

        $this->assertDatabaseHas('employee_documents', ['id' => $document->id]);
    }

    public function test_a_missing_object_answers_404_without_deleting_the_record(): void
    {
        $document = $this->uploaded('WILL-VANISH');
        unset($this->s3->objects[$document->file_path]);

        $this->download($document)->assertStatus(404)->assertJsonPath('error_code', 'DOCUMENT_FILE_MISSING');

        $this->assertDatabaseHas('employee_documents', ['id' => $document->id]);
    }

    public function test_an_object_damaged_in_the_bucket_is_never_served(): void
    {
        $document = $this->uploaded('ORIGINAL-BYTES');
        $this->s3->seed($document->file_path, 'DAMAGED-BYTES!'); // same length (14), different bytes
        $this->assertSame(strlen('ORIGINAL-BYTES'), strlen('DAMAGED-BYTES!'));

        $response = $this->download($document);

        $response->assertStatus(502)->assertJsonPath('error_code', 'DOCUMENT_INTEGRITY_FAILED');
        $this->assertStringNotContainsString('DAMAGED', (string) $response->getContent());
    }

    public function test_a_read_error_on_the_bucket_is_a_temporary_503_not_a_missing_file(): void
    {
        $document = $this->uploaded('READ-ERROR');
        $this->s3->failNextReads();

        $this->download($document)->assertStatus(503);

        $this->assertArrayHasKey($document->file_path, $this->s3->objects);
    }

    // ---- isolation ----------------------------------------------------------------------------------------------------

    public function test_a_document_cannot_be_downloaded_through_another_employee_and_the_bucket_is_not_even_asked(): void
    {
        $document = $this->uploaded('PRIVATE-TO-ONE');
        $other = $this->makeEmployee();
        $this->s3->requests = [];

        $this->download($document, $other->id)->assertStatus(404);

        $this->assertSame([], $this->s3->requests);
    }

    public function test_a_stored_path_that_escapes_the_employee_folder_is_never_requested_from_the_bucket(): void
    {
        $document = $this->uploaded('SAFE');
        $victim = $this->uploaded('SOMEONE-ELSES', $this->makeEmployee());
        $document->forceFill(['file_path' => '../'.$victim->file_path])->save();
        $this->s3->requests = [];

        $this->download($document)->assertStatus(404);

        $this->assertSame([], $this->s3->requests);
    }

    public function test_an_unauthenticated_request_never_reaches_the_bucket(): void
    {
        $document = $this->uploaded('LOCKED');
        $this->s3->requests = [];
        $this->flushHeaders(); // withToken() persists on the test client; this request must carry no token
        $this->app['auth']->forgetGuards(); // and the guard must not remember the earlier request's user

        $this->get("/api/v1/admin/hr/employees/{$this->employee->id}/documents/{$document->id}/download", ['Accept' => 'application/json'])->assertStatus(401);

        $this->assertSame([], $this->s3->requests);
    }

    // ---- mirror and restore through the real chain --------------------------------------------------------------------

    public function test_the_mirror_copies_from_the_bucket_to_a_separate_disk_and_verifies_every_copy(): void
    {
        $this->fakeDisk('mirror_volume');
        $a = $this->uploaded('ALPHA-DOCUMENT');
        $b = $this->uploaded('BRAVO-DOCUMENT');

        Artisan::call('hr:documents-mirror', ['--mirror-disk' => 'mirror_volume', '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertTrue($report['ok']);
        $this->assertSame(2, $report['counts']['copied']);
        $this->assertSame('ALPHA-DOCUMENT', Storage::disk('mirror_volume')->get($a->file_path));
        $this->assertSame('BRAVO-DOCUMENT', Storage::disk('mirror_volume')->get($b->file_path));
        // The source was only read, never changed or deleted.
        $this->assertSame([], $this->s3->deleted);
        $this->assertSame([], $this->s3->requestsOf('DELETE'));
        $this->assertCount(2, $this->s3->objects);
    }

    public function test_a_lost_bucket_is_refilled_from_the_verified_mirror_and_nothing_is_deleted(): void
    {
        $this->fakeDisk('mirror_volume');
        $a = $this->uploaded('ALPHA-DOCUMENT');
        $b = $this->uploaded('BRAVO-DOCUMENT');
        $c = $this->uploaded('CHARLIE-DOCUMENT');
        Artisan::call('hr:documents-mirror', ['--mirror-disk' => 'mirror_volume']);

        // The bucket is lost: a brand-new, empty bucket replaces it.
        $this->useFakeS3();
        $this->assertSame([], $this->s3->objects);

        Artisan::call('hr:documents-restore', ['--mirror-disk' => 'mirror_volume', '--execute' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertTrue($report['ok']);
        $this->assertSame(3, $report['counts']['restored']);
        $this->assertSame(0, $report['needs_attention']);
        foreach ([[$a, 'ALPHA-DOCUMENT'], [$b, 'BRAVO-DOCUMENT'], [$c, 'CHARLIE-DOCUMENT']] as [$document, $content]) {
            $this->assertSame($content, $this->s3->objects[$document->file_path]['bytes']);
            $this->assertSame($content, $this->download($document)->assertOk()->streamedContent());
            Storage::disk('mirror_volume')->assertExists($document->file_path); // the backup copy is still there
        }
        $this->assertSame([], $this->s3->deleted);
    }

    public function test_a_document_that_is_not_in_the_mirror_is_reported_unrecoverable_and_never_called_restored(): void
    {
        $this->fakeDisk('mirror_volume');
        $mirrored = $this->uploaded('MIRRORED');
        Artisan::call('hr:documents-mirror', ['--mirror-disk' => 'mirror_volume']);
        $late = $this->uploaded('UPLOADED-AFTER-THE-LAST-MIRROR');

        $this->useFakeS3();
        Artisan::call('hr:documents-restore', ['--mirror-disk' => 'mirror_volume', '--execute' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertFalse($report['ok']);
        $this->assertSame(1, $report['counts']['restored']);
        $this->assertSame(1, $report['counts']['not_in_mirror']);
        $this->assertArrayHasKey($mirrored->file_path, $this->s3->objects);
        $this->assertArrayNotHasKey($late->file_path, $this->s3->objects);
    }

    public function test_a_damaged_mirror_copy_is_never_restored(): void
    {
        $this->fakeDisk('mirror_volume');
        $document = $this->uploaded('GOOD-ORIGINAL');
        Artisan::call('hr:documents-mirror', ['--mirror-disk' => 'mirror_volume']);
        Storage::disk('mirror_volume')->put($document->file_path, 'BAD--ORIGINAL!'); // same length, different bytes

        $this->useFakeS3();
        Artisan::call('hr:documents-restore', ['--mirror-disk' => 'mirror_volume', '--execute' => true, '--json' => true]);
        $report = json_decode(Artisan::output(), true);

        $this->assertFalse($report['ok']);
        $this->assertSame(0, $report['counts']['restored']);
        $this->assertSame(1, $report['counts']['mirror_checksum_mismatch']);
        $this->assertSame([], $this->s3->objects);
    }
}
