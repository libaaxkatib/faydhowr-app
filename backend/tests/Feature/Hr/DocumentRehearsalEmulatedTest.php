<?php

namespace Tests\Feature\Hr;

use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeDocument;
use App\Support\Hr\Documents\DocumentMirror;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakeS3Server;
use Tests\Support\FakesDocumentDisk;
use Tests\Support\InMemoryObjectStore;
use Tests\TestCase;

/**
 * An EMULATED end-to-end backup and restore rehearsal with a synthetic dataset: the real application code, the real S3 chain
 * (Flysystem adapter + AWS SDK) against an in-process fake endpoint standing in for the R2 bucket, and a local folder standing in
 * for the Railway volume. Nothing external is contacted and no real document is involved.
 *
 * It is evidence for our code, not for Cloudflare R2 or Railway. When REHEARSAL_EVIDENCE_FILE names a new file the full set of
 * counts and checksums is written there (an existing file is never overwritten).
 */
class DocumentRehearsalEmulatedTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private FakeS3Server $bucket;

    /** @var array<string, mixed> */
    private array $evidence = [];

    private function useFreshBucket(): void
    {
        $this->bucket = new FakeS3Server;
        config([
            'filesystems.disks.r2_documents' => array_merge((array) config('filesystems.disks.r2_documents'), $this->bucket->diskConfig()),
            'filesystems.hr_documents_disk' => 'r2_documents',
        ]);
        Storage::purge('r2_documents');
    }

    private function syntheticBytes(int $i, int $size): string
    {
        $seed = hash('sha256', "synthetic-document-{$i}", true);

        return substr(str_repeat($seed, intdiv($size, 32) + 1), 0, $size);
    }

    /** @return array<string, mixed> */
    private function artisanJson(string $command, array $options): array
    {
        Artisan::call($command, $options + ['--json' => true]);

        return json_decode(Artisan::output(), true);
    }

    public function test_synthetic_dataset_backup_loss_and_verified_restore(): void
    {
        $this->fakeLocalDisk();
        $this->useFreshBucket();
        $this->fakeDisk('mirror_volume');
        $token = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;

        // 1. Upload 24 synthetic documents for 6 synthetic employees through the real API into the (emulated) bucket.
        $employees = [];
        for ($e = 0; $e < 6; $e++) {
            $employees[] = Employee::query()->create([
                'employee_number' => 'SYN-'.str_pad((string) $e, 4, '0', STR_PAD_LEFT),
                'full_name' => "Synthetic Person {$e}",
                'phone' => fake()->unique()->e164PhoneNumber(),
                'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
                'status' => 'active',
                'application_date' => now()->toDateString(),
            ]);
        }

        $originals = []; // document id => [sha256, size, bytes]
        $sizes = [1, 17, 1024, 4096, 65536, 262144];
        for ($i = 0; $i < 24; $i++) {
            $bytes = $this->syntheticBytes($i, $sizes[$i % count($sizes)]);
            $employee = $employees[$i % 6];
            $id = $this->withToken($token)->post("/api/v1/admin/hr/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->createWithContent("synthetic-{$i}.pdf", $bytes),
            ], ['Accept' => 'application/json'])->assertStatus(201)->json('data.id');
            $originals[$id] = ['sha256' => hash('sha256', $bytes), 'size' => strlen($bytes), 'bytes' => $bytes];
        }
        $ids = array_keys($originals);
        $this->assertCount(24, $this->bucket->objects);
        $this->evidence['uploaded'] = ['documents' => 24, 'employees' => 6, 'bytes_total' => array_sum(array_column($originals, 'size')), 'bucket_objects' => count($this->bucket->objects)];

        // 2. Mirror faults against a throw-away in-memory mirror: a full volume, then interrupted copies that succeed on retry.
        $faulty = $this->useObjectStore('mirror_faulty', new InMemoryObjectStore);
        $faulty->full = true;
        $full = (new DocumentMirror('r2_documents', 'mirror_faulty', 1, 0))->mirror();
        $this->assertFalse($full['ok']);
        $this->assertSame(0, $full['counts']['copied']);
        $this->assertSame(24, $full['counts']['copy_failed']);
        $this->evidence['mirror_full_volume'] = ['ok' => $full['ok'], 'copied' => $full['counts']['copied'], 'copy_failed' => $full['counts']['copy_failed'], 'partial_objects_left' => count($faulty->objects)];
        $this->assertSame([], $faulty->objects, 'A full volume leaves nothing half-written behind.');

        $faulty->full = false;
        // Five cut-short copies in a row with three tries per document: the first document uses all three tries and is reported
        // failed (not hidden), the next document recovers on its third try, and a later run completes the one that failed.
        $faulty->failNextWrites = 5;
        $interrupted = (new DocumentMirror('r2_documents', 'mirror_faulty', 3, 0))->mirror();
        $this->assertFalse($interrupted['ok'], 'a document that could not be copied makes the run unhealthy');
        $this->assertSame(23, $interrupted['counts']['copied']);
        $this->assertSame(1, $interrupted['counts']['copy_failed']);
        $this->assertCount(1, $interrupted['failures']);
        $retry = (new DocumentMirror('r2_documents', 'mirror_faulty', 3, 0))->mirror();
        $this->assertTrue($retry['ok']);
        $this->assertSame(1, $retry['counts']['copied']);
        $this->assertSame(23, $retry['counts']['already_present']);
        $this->evidence['mirror_interrupted_copies'] = [
            'injected_failures' => 5,
            'tries_per_document' => 3,
            'run_1' => ['ok' => $interrupted['ok'], 'copied' => $interrupted['counts']['copied'], 'copy_failed' => $interrupted['counts']['copy_failed']],
            'run_2' => ['ok' => $retry['ok'], 'copied' => $retry['counts']['copied'], 'already_present' => $retry['counts']['already_present']],
        ];
        foreach ($originals as $id => $o) {
            $this->assertSame($o['sha256'], hash('sha256', $faulty->objects[EmployeeDocument::query()->findOrFail($id)->file_path]), 'every retried copy is byte-identical');
        }

        // 3. The real mirror run onto the folder standing in for the Railway volume; a second run changes nothing.
        $first = $this->artisanJson('hr:documents-mirror', ['--mirror-disk' => 'mirror_volume', '--deep' => true]);
        $second = $this->artisanJson('hr:documents-mirror', ['--mirror-disk' => 'mirror_volume', '--deep' => true]);
        $this->assertTrue($first['ok']);
        $this->assertSame(24, $first['counts']['copied']);
        $this->assertTrue($second['ok']);
        $this->assertSame(0, $second['counts']['copied']);
        $this->assertSame(24, $second['counts']['already_present']);
        $this->evidence['mirror_run_1'] = $first['counts'];
        $this->evidence['mirror_run_2_idempotent'] = $second['counts'];
        $check = $this->artisanJson('hr:documents-mirror-check', ['--mirror-disk' => 'mirror_volume']);
        $this->assertTrue($check['ok'] ?? false, json_encode($check));
        $this->evidence['mirror_freshness_check'] = $check;

        foreach ($originals as $id => $o) {
            $path = EmployeeDocument::query()->findOrFail($id)->file_path;
            $this->assertSame($o['sha256'], hash('sha256', Storage::disk('mirror_volume')->get($path)), 'mirror copy matches the original');
            $this->assertSame($o['sha256'], hash('sha256', $this->bucket->objects[$path]['bytes']), 'bucket copy matches the original');
        }

        // 4. Partial loss: three objects vanish from the bucket. The report says what is restorable; restore fills exactly those.
        $lost = array_slice($ids, 0, 3);
        foreach ($lost as $id) {
            unset($this->bucket->objects[EmployeeDocument::query()->findOrFail($id)->file_path]);
        }
        $report = $this->artisanJson('hr:documents-restore', ['--mirror-disk' => 'mirror_volume']);
        $this->assertSame(3, $report['counts']['restorable']);
        $this->assertSame(0, $report['counts']['restored']);
        $this->assertCount(21, $this->bucket->objects, 'report-only changes nothing');
        $partial = $this->artisanJson('hr:documents-restore', ['--mirror-disk' => 'mirror_volume', '--execute' => true]);
        $this->assertTrue($partial['ok']);
        $this->assertSame(3, $partial['counts']['restored']);
        $this->assertSame(21, $partial['counts']['present']);
        $this->assertCount(24, $this->bucket->objects);
        $this->evidence['partial_loss_restore'] = ['lost' => 3, 'report_only_restorable' => $report['counts']['restorable'], 'counts' => $partial['counts']];

        // 5. Total loss: the bucket is replaced by a brand-new empty one. Three documents cannot be proven good and must not be
        //    labelled recovered: one mirror copy is damaged, one record has no recorded checksum, one never reached the mirror.
        $damaged = $ids[3];
        $noChecksum = $ids[4];
        $late = $ids[5];
        $damagedPath = EmployeeDocument::query()->findOrFail($damaged)->file_path;
        Storage::disk('mirror_volume')->put($damagedPath, strrev($originals[$damaged]['bytes']) === $originals[$damaged]['bytes'] ? $originals[$damaged]['bytes'].'!' : strrev($originals[$damaged]['bytes']));
        EmployeeDocument::query()->whereKey($noChecksum)->update(['sha256' => null]);
        Storage::disk('mirror_volume')->delete(EmployeeDocument::query()->findOrFail($late)->file_path);
        $mirrorBefore = collect(Storage::disk('mirror_volume')->allFiles())->reject(fn ($f) => str_starts_with($f, '_mirror'))->sort()->values()->all();

        $this->useFreshBucket();
        $this->assertSame([], $this->bucket->objects);
        $total = $this->artisanJson('hr:documents-restore', ['--mirror-disk' => 'mirror_volume', '--execute' => true]);

        $this->assertFalse($total['ok']);
        $this->assertSame(21, $total['counts']['restored']);
        $this->assertSame(1, $total['counts']['mirror_checksum_mismatch']);
        $this->assertSame(1, $total['counts']['unverifiable_no_recorded_checksum']);
        $this->assertSame(1, $total['counts']['not_in_mirror']);
        $this->assertSame(3, $total['needs_attention']);
        $this->assertSame(0, $total['counts']['restore_failed']);

        // Independent verification of everything reported as restored, and proof the three were not restored.
        $unrecovered = [$damaged, $noChecksum, $late];
        $verified = 0;
        foreach ($originals as $id => $o) {
            $path = EmployeeDocument::query()->findOrFail($id)->file_path;
            if (in_array($id, $unrecovered, true)) {
                $this->assertArrayNotHasKey($path, $this->bucket->objects, 'an unverified file is never placed in the bucket');

                continue;
            }
            $this->assertSame($o['sha256'], hash('sha256', $this->bucket->objects[$path]['bytes']), "document {$id} restored byte-identically");
            $verified++;
        }
        $this->assertSame(21, $verified);
        $this->assertCount(21, $this->bucket->objects);

        // No copy anywhere was deleted by the restore: the backup still holds what it held, and the bucket saw no delete.
        $mirrorAfter = collect(Storage::disk('mirror_volume')->allFiles())->reject(fn ($f) => str_starts_with($f, '_mirror'))->sort()->values()->all();
        $this->assertSame($mirrorBefore, $mirrorAfter);
        $this->assertSame([], $this->bucket->deleted);
        $this->assertSame([], $this->bucket->requestsOf('DELETE'));

        // A restored document downloads through the application again.
        $restoredId = $ids[10];
        $this->withToken($token)->get('/api/v1/admin/hr/employees/'.EmployeeDocument::query()->findOrFail($restoredId)->employee_id."/documents/{$restoredId}/download")
            ->assertOk()->assertHeader('Cache-Control', 'no-store, private');

        $this->evidence['total_loss_restore'] = [
            'documents_in_database' => 24,
            'restored_and_independently_verified' => $verified,
            'unrecovered_not_labelled_recovered' => count($unrecovered),
            'reasons' => ['mirror_checksum_mismatch' => 1, 'unverifiable_no_recorded_checksum' => 1, 'not_in_mirror' => 1],
            'restore_counts' => $total['counts'],
            'backup_files_before' => count($mirrorBefore),
            'backup_files_after' => count($mirrorAfter),
            'objects_deleted_anywhere' => count($this->bucket->deleted),
        ];
        $this->evidence['documents'] = collect($originals)->map(fn ($o, $id) => ['document_id' => $id, 'size' => $o['size'], 'sha256' => $o['sha256'], 'restored_and_verified' => ! in_array($id, $unrecovered, true)])->values()->all();
        $this->evidence['scope'] = 'EMULATED: in-process fake S3 endpoint for the bucket and a local folder for the volume. Synthetic data only. Not evidence for Cloudflare R2 or Railway.';

        $this->writeEvidence();
    }

    private function writeEvidence(): void
    {
        $path = getenv('REHEARSAL_EVIDENCE_FILE');

        if (! is_string($path) || $path === '') {
            return;
        }

        $this->assertFileDoesNotExist($path, 'Evidence files are never overwritten.');
        $this->assertNotFalse(file_put_contents($path, json_encode($this->evidence, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES), LOCK_EX));
    }
}
