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
use Tests\Support\InMemoryObjectStore;
use Tests\TestCase;

/**
 * The recovery mirror: a verified, copy-only second copy that can be restored from - and nothing more. Covers copying with
 * checksum verification, idempotence, retries, damaged and conflicting objects, a full or unreachable disk, freshness
 * monitoring, restoring into an empty environment, and the guarantees that nothing is deleted, overwritten or changed in the
 * database. Isolated fake disks and an in-memory object store only.
 */
class DocumentMirrorTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private Employee $employee;

    private int $uploaderId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeLocalDisk();
        $this->fakeDisk('hr_mirror');
        config(['filesystems.hr_documents_mirror_disk' => 'hr_mirror']);
        $this->uploaderId = Admin::factory()->create()->id;
        $this->employee = Employee::query()->create([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => 'Mirror Probe',
            'phone' => fake()->unique()->e164PhoneNumber(),
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'status' => 'active',
            'application_date' => now()->toDateString(),
        ]);
    }

    /** A document on the primary ('local') disk with its file written and its checksum recorded. */
    private function document(string $content, bool $withFile = true, bool $recordChecksum = true, ?string $recordedSha = null): EmployeeDocument
    {
        $path = "employees/{$this->employee->id}/documents/".bin2hex(random_bytes(8)).'.pdf';

        if ($withFile) {
            Storage::disk('local')->put($path, $content);
        }

        return EmployeeDocument::query()->create([
            'employee_id' => $this->employee->id, 'admin_id' => $this->uploaderId, 'file_name' => 'private-name.pdf', 'file_type' => 'pdf',
            'file_size' => strlen($content), 'file_path' => $path, 'disk' => 'local',
            'sha256' => $recordChecksum ? ($recordedSha ?? hash('sha256', $content)) : null,
            'verification_status' => 'pending', 'is_current' => true,
        ]);
    }

    /** @return array<string, mixed> */
    private function runCommand(string $command, array $options = []): array
    {
        $exit = Artisan::call($command, [...$options, '--json' => true]);
        $report = json_decode(Artisan::output(), true);
        $this->assertIsArray($report, 'The command must print valid JSON.');
        $report['_exit'] = $exit;

        return $report;
    }

    /** @return array<string, string> path => sha256 of every file on a fake disk */
    private function snapshot(string $disk): array
    {
        $files = [];
        foreach (Storage::disk($disk)->allFiles() as $file) {
            $files[$file] = hash('sha256', Storage::disk($disk)->get($file));
        }
        ksort($files);

        return $files;
    }

    public function test_every_document_is_copied_verified_and_recorded_in_a_manifest_and_a_second_run_changes_nothing(): void
    {
        $docs = [$this->document('ONE'), $this->document('TWO-TWO'), $this->document('THREE-THREE-THREE')];

        $first = $this->runCommand('hr:documents-mirror');

        $this->assertSame(0, $first['_exit']);
        $this->assertTrue($first['ok']);
        $this->assertSame(3, $first['counts']['copied']);
        foreach ($docs as $doc) {
            $this->assertSame(Storage::disk('local')->get($doc->file_path), Storage::disk('hr_mirror')->get($doc->file_path));
        }
        $last = json_decode(Storage::disk('hr_mirror')->get('_mirror/last-run.json'), true);
        $this->assertSame(0, $last['failed_total']);
        $manifests = Storage::disk('hr_mirror')->allFiles('_mirror/manifests');
        $this->assertCount(1, $manifests);
        $manifest = (string) Storage::disk('hr_mirror')->get($manifests[0]);
        $this->assertStringContainsString($docs[0]->sha256, $manifest);
        $this->assertStringNotContainsString('private-name', $manifest, 'The manifest holds ids, paths and checksums only - no file names.');

        $documentsOnly = fn (array $snapshot): array => array_filter($snapshot, fn (string $path): bool => ! str_starts_with($path, '_mirror/'), ARRAY_FILTER_USE_KEY);
        $before = $documentsOnly($this->snapshot('hr_mirror'));
        $second = $this->runCommand('hr:documents-mirror');

        $this->assertSame(0, $second['counts']['copied']);
        $this->assertSame(3, $second['counts']['already_present']);
        $this->assertSame($before, $documentsOnly($this->snapshot('hr_mirror')), 'Mirrored documents are untouched by a second run.');
        $this->assertCount(2, Storage::disk('hr_mirror')->allFiles('_mirror/manifests'), 'Manifests are kept, not overwritten.');
    }

    public function test_a_routine_run_does_not_re_read_what_is_already_mirrored_but_a_deep_run_re_verifies_everything(): void
    {
        $store = $this->useObjectStore('hr_object_mirror', new InMemoryObjectStore);
        config(['filesystems.hr_documents_mirror_disk' => 'hr_object_mirror']);
        $doc = $this->document('ORIGINAL-BYTES');
        $this->runCommand('hr:documents-mirror');
        $store->reads = 0;

        $routine = $this->runCommand('hr:documents-mirror');

        $this->assertSame(1, $routine['counts']['already_present']);
        $this->assertSame(0, $store->reads, 'A routine run must not download the mirrored objects again.');

        // A same-size substitution on the mirror is invisible to the cheap check (documented) ...
        $store->objects[$doc->file_path] = 'ORIGINAL-BYTEX';
        $this->assertSame(0, $this->runCommand('hr:documents-mirror')['_exit']);

        // ... and caught by the deep run, which never overwrites it either.
        $deep = $this->runCommand('hr:documents-mirror', ['--deep' => true]);
        $this->assertSame(1, $deep['counts']['mirror_conflict']);
        $this->assertSame(1, $deep['_exit']);
        $this->assertGreaterThan(0, $store->reads);
        $this->assertSame('ORIGINAL-BYTEX', $store->objects[$doc->file_path]);
        $this->assertSame([], $store->deleted);
    }

    public function test_a_routine_run_still_notices_a_source_that_has_gone_missing(): void
    {
        $doc = $this->document('WILL-GO');
        $this->runCommand('hr:documents-mirror');
        Storage::disk('local')->delete($doc->file_path);

        $routine = $this->runCommand('hr:documents-mirror');

        $this->assertSame(1, $routine['counts']['source_missing']);
        $this->assertSame(0, $routine['counts']['already_present']);
        $this->assertSame(1, $routine['_exit']);
        Storage::disk('hr_mirror')->assertExists($doc->file_path);
    }

    public function test_a_dry_run_reports_what_would_be_copied_and_writes_nothing(): void
    {
        $this->document('ONE');
        $this->document('TWO');

        $report = $this->runCommand('hr:documents-mirror', ['--dry-run' => true]);

        $this->assertSame(0, $report['_exit']);
        $this->assertSame(2, $report['counts']['would_copy']);
        $this->assertSame(0, $report['counts']['copied']);
        $this->assertSame([], Storage::disk('hr_mirror')->allFiles());
    }

    public function test_nothing_is_ever_deleted_from_the_mirror_and_the_primary_is_only_read(): void
    {
        $store = $this->useObjectStore('hr_object_mirror', new InMemoryObjectStore);
        config(['filesystems.hr_documents_mirror_disk' => 'hr_object_mirror']);
        $gone = $this->document('WILL-VANISH-FROM-PRIMARY');
        $kept = $this->document('STAYS');
        $this->runCommand('hr:documents-mirror');
        $this->assertArrayHasKey($gone->file_path, $store->objects);

        // The primary loses a file and a record is removed: the mirror must keep its copies.
        Storage::disk('local')->delete($gone->file_path);
        $kept->delete();
        $primaryBefore = $this->snapshot('local');

        $report = $this->runCommand('hr:documents-mirror');

        $this->assertSame(1, $report['counts']['source_missing']);
        $this->assertSame(1, $report['_exit'], 'A document that is missing at the source is a failure to report, not something to hide.');
        $this->assertSame([], $store->deleted, 'The mirror run must not delete a single object.');
        $this->assertSame('WILL-VANISH-FROM-PRIMARY', $store->objects[$gone->file_path], 'The recoverable copy of the lost file is still there.');
        $this->assertSame($primaryBefore, $this->snapshot('local'), 'The primary disk was only read.');
    }

    public function test_a_source_that_does_not_match_its_recorded_checksum_is_never_copied(): void
    {
        $damaged = $this->document('BAD-BYTES', recordedSha: hash('sha256', 'WHAT-WAS-UPLOADED'));
        $good = $this->document('GOOD');

        $report = $this->runCommand('hr:documents-mirror');

        $this->assertSame(1, $report['counts']['source_checksum_mismatch']);
        $this->assertSame(1, $report['counts']['copied']);
        $this->assertSame(1, $report['_exit']);
        Storage::disk('hr_mirror')->assertMissing($damaged->file_path);
        Storage::disk('hr_mirror')->assertExists($good->file_path);
    }

    public function test_a_different_object_already_on_the_mirror_is_reported_and_never_overwritten(): void
    {
        $doc = $this->document('REAL-CONTENT');
        Storage::disk('hr_mirror')->put($doc->file_path, 'SOMETHING-ELSE');

        $report = $this->runCommand('hr:documents-mirror');

        $this->assertSame(1, $report['counts']['mirror_conflict']);
        $this->assertSame(1, $report['_exit']);
        $this->assertSame('SOMETHING-ELSE', Storage::disk('hr_mirror')->get($doc->file_path));
    }

    public function test_an_interrupted_transfer_is_retried_and_the_cut_short_object_is_removed(): void
    {
        $store = $this->useObjectStore('hr_object_mirror', new InMemoryObjectStore);
        config(['filesystems.hr_documents_mirror_disk' => 'hr_object_mirror']);
        $doc = $this->document('COMPLETE-CONTENT-OF-THE-DOCUMENT');
        $store->failNextWrites = 1;

        $report = $this->runCommand('hr:documents-mirror', ['--attempts' => 3]);

        $this->assertSame(0, $report['_exit']);
        $this->assertSame(1, $report['counts']['copied']);
        $this->assertSame('COMPLETE-CONTENT-OF-THE-DOCUMENT', $store->objects[$doc->file_path]);
        $this->assertSame([$doc->file_path], $store->deleted, 'Only the cut-short object this run itself had just written was removed.');
    }

    public function test_a_transfer_that_keeps_failing_is_reported_leaves_no_partial_object_and_marks_the_run_unhealthy(): void
    {
        $store = $this->useObjectStore('hr_object_mirror', new InMemoryObjectStore);
        config(['filesystems.hr_documents_mirror_disk' => 'hr_object_mirror']);
        $this->document('NEVER-ARRIVES');
        $store->failNextWrites = 2;

        $report = $this->runCommand('hr:documents-mirror', ['--attempts' => 2]);

        $this->assertSame(1, $report['counts']['copy_failed']);
        $this->assertSame(1, $report['_exit']);
        $this->assertSame([], array_values(array_filter(array_keys($store->objects), fn (string $p): bool => ! str_starts_with($p, '_mirror/'))), 'No partial document object may remain.');
        // The run record says it failed, so the freshness check turns red even though the run itself finished.
        $check = $this->runCommand('hr:documents-mirror-check');
        $this->assertSame(1, $check['_exit']);
        $this->assertSame('last_run_had_failures', $check['reason']);
    }

    public function test_a_copy_that_is_silently_corrupted_is_detected_by_the_read_back(): void
    {
        $store = $this->useObjectStore('hr_object_mirror', new InMemoryObjectStore);
        config(['filesystems.hr_documents_mirror_disk' => 'hr_object_mirror']);
        $doc = $this->document('PRECISE-BYTES');
        $store->corruptNextWrites = 1;

        $once = $this->runCommand('hr:documents-mirror', ['--attempts' => 1]);
        $this->assertSame(1, $once['counts']['copy_failed']);
        $this->assertArrayNotHasKey($doc->file_path, $store->objects, 'A copy that failed verification must not stay behind as a "backup".');

        $this->assertSame(0, $this->runCommand('hr:documents-mirror')['_exit']);
        $this->assertSame('PRECISE-BYTES', $store->objects[$doc->file_path]);
    }

    public function test_a_full_mirror_volume_is_reported_and_nothing_is_claimed_as_copied(): void
    {
        $store = $this->useObjectStore('hr_object_mirror', new InMemoryObjectStore);
        config(['filesystems.hr_documents_mirror_disk' => 'hr_object_mirror']);
        $this->document('TOO-BIG-FOR-WHAT-IS-LEFT');
        $store->full = true;

        $report = $this->runCommand('hr:documents-mirror', ['--attempts' => 1]);

        $this->assertSame(0, $report['counts']['copied']);
        $this->assertSame(1, $report['counts']['copy_failed']);
        $this->assertSame(1, $report['_exit']);
        $this->assertFalse($report['ok']);
        $this->assertSame([], $store->objects);
    }

    public function test_a_manifest_that_cannot_be_written_leaves_no_cut_short_file_and_keeps_the_previous_last_run(): void
    {
        // Found on a real, nearly full volume: the failed manifest write left an empty file behind.
        $store = $this->useObjectStore('hr_object_mirror', new InMemoryObjectStore);
        config(['filesystems.hr_documents_mirror_disk' => 'hr_object_mirror']);
        $this->document('MIRRORED-ONCE');
        $this->assertSame(0, $this->runCommand('hr:documents-mirror')['_exit']);
        $lastRun = $store->objects['_mirror/last-run.json'];
        $manifestsBefore = array_keys(array_filter($store->objects, fn ($k) => str_starts_with($k, '_mirror/manifests/'), ARRAY_FILTER_USE_KEY));

        $store->failNextWrites = 1; // nothing needs copying now, so the next write is the manifest

        $report = $this->runCommand('hr:documents-mirror', ['--attempts' => 1]);

        $this->assertSame(1, $report['counts']['manifest_failed']);
        $this->assertSame(1, $report['_exit']);
        $manifestsAfter = array_keys(array_filter($store->objects, fn ($k) => str_starts_with($k, '_mirror/manifests/'), ARRAY_FILTER_USE_KEY));
        $this->assertSame($manifestsBefore, $manifestsAfter, 'no empty or cut-short manifest may remain');
        $this->assertSame($lastRun, $store->objects['_mirror/last-run.json'], 'the previous last-run record is untouched');
        $this->assertSame('MIRRORED-ONCE', $store->objects[EmployeeDocument::query()->firstOrFail()->file_path], 'the mirrored document is untouched');
    }

    public function test_an_unreachable_disk_aborts_the_run_and_nothing_is_called_missing_or_copied(): void
    {
        $this->document('ONE');
        $this->makeDiskUnreachable('hr_mirror');
        $mirrorRoot = (string) config('filesystems.disks.hr_mirror.root');

        $report = $this->runCommand('hr:documents-mirror');

        $this->assertSame('mirror_unavailable', $report['aborted']);
        $this->assertSame(1, $report['_exit']);
        $this->assertSame(0, $report['counts']['considered']);
        $this->assertDirectoryDoesNotExist($mirrorRoot, 'Looking at the mirror must never recreate its missing folder.');

        $this->fakeDisk('hr_mirror');
        $this->makeStorageUnreachable();
        $primary = $this->runCommand('hr:documents-mirror');
        $this->assertSame('primary_unavailable', $primary['aborted']);
        $this->assertSame(0, $primary['counts']['source_missing']);
    }

    public function test_the_mirror_commands_refuse_without_a_mirror_or_when_it_is_the_primary(): void
    {
        config(['filesystems.hr_documents_mirror_disk' => null]);
        $this->assertSame(1, Artisan::call('hr:documents-mirror'));
        $this->assertStringContainsString('No recovery-mirror disk is configured', Artisan::output());
        $this->assertSame(1, Artisan::call('hr:documents-restore'));

        config(['filesystems.hr_documents_mirror_disk' => 'local']);
        $report = $this->runCommand('hr:documents-mirror');
        $this->assertSame('mirror_is_the_primary_disk', $report['aborted']);
        $this->assertSame(1, Artisan::call('hr:documents-mirror', ['--mirror-disk' => 'nope']));
    }

    public function test_the_freshness_check_is_green_only_for_a_recent_clean_run(): void
    {
        $this->document('ONE');

        $none = $this->runCommand('hr:documents-mirror-check');
        $this->assertSame(1, $none['_exit']);
        $this->assertSame('no_run_recorded', $none['reason']);

        $this->runCommand('hr:documents-mirror');
        $fresh = $this->runCommand('hr:documents-mirror-check');
        $this->assertSame(0, $fresh['_exit']);
        $this->assertTrue($fresh['ok']);

        $this->travel(30)->hours();
        $stale = $this->runCommand('hr:documents-mirror-check', ['--max-age-hours' => 26]);
        $this->assertSame(1, $stale['_exit']);
        $this->assertSame('stale', $stale['reason']);
        $this->assertSame(0, $this->runCommand('hr:documents-mirror-check', ['--max-age-hours' => 48])['_exit']);
        $this->travelBack();

        $this->makeDiskUnreachable('hr_mirror');
        $this->assertSame('mirror_unavailable', $this->runCommand('hr:documents-mirror-check')['reason']);
    }

    public function test_restore_reports_first_and_then_restores_only_verified_files_into_an_empty_primary(): void
    {
        $restorable = $this->document('RESTORE-ME');
        $tampered = $this->document('TAMPERED-IN-MIRROR');
        $present = $this->document('STILL-HERE');
        $neverMirrored = $this->document('NOT-MIRRORED');
        $legacy = $this->document('LEGACY-NO-CHECKSUM', recordChecksum: false);
        // Mirror everything except $neverMirrored (a document uploaded after the last run).
        $neverMirrored->update(['disk' => 'somewhere_else']);
        $this->runCommand('hr:documents-mirror');
        $neverMirrored->update(['disk' => 'local']);
        Storage::disk('hr_mirror')->put($tampered->file_path, 'TAMPERED-IN-MIRROR-X');
        // The primary loses four files; one stays.
        foreach ([$restorable, $tampered, $neverMirrored, $legacy] as $lost) {
            Storage::disk('local')->delete($lost->file_path);
        }
        $primaryBefore = $this->snapshot('local');
        $mirrorBefore = $this->snapshot('hr_mirror');
        $rows = EmployeeDocument::query()->orderBy('id')->get()->toJson();

        $report = $this->runCommand('hr:documents-restore');

        $this->assertSame(1, $report['counts']['restorable']);
        $this->assertSame(1, $report['counts']['present']);
        $this->assertSame(1, $report['counts']['mirror_checksum_mismatch']);
        $this->assertSame(1, $report['counts']['not_in_mirror']);
        $this->assertSame(1, $report['counts']['unverifiable_no_recorded_checksum']);
        $this->assertSame(0, $report['counts']['restored']);
        $this->assertSame(1, $report['_exit'], 'Documents that cannot be recovered must make the report fail loudly.');
        $this->assertSame($primaryBefore, $this->snapshot('local'), 'Without --execute nothing is written.');

        $done = $this->runCommand('hr:documents-restore', ['--execute' => true]);

        $this->assertSame(1, $done['counts']['restored']);
        $this->assertSame('RESTORE-ME', Storage::disk('local')->get($restorable->file_path));
        Storage::disk('local')->assertMissing($tampered->file_path);
        Storage::disk('local')->assertMissing($neverMirrored->file_path);
        Storage::disk('local')->assertMissing($legacy->file_path);
        $this->assertSame('STILL-HERE', Storage::disk('local')->get($present->file_path), 'An existing object is never overwritten.');
        $this->assertSame($mirrorBefore, $this->snapshot('hr_mirror'), 'The mirror is only read.');
        $this->assertSame($rows, EmployeeDocument::query()->orderBy('id')->get()->toJson(), 'Restore never changes the database.');

        $again = $this->runCommand('hr:documents-restore', ['--execute' => true]);
        $this->assertSame(0, $again['counts']['restored']);
        $this->assertSame(2, $again['counts']['present']);
    }

    public function test_restore_can_be_limited_to_chosen_documents_and_refuses_when_storage_is_unreachable(): void
    {
        $a = $this->document('A-CONTENT');
        $b = $this->document('B-CONTENT');
        $this->runCommand('hr:documents-mirror');
        Storage::disk('local')->delete($a->file_path);
        Storage::disk('local')->delete($b->file_path);

        $one = $this->runCommand('hr:documents-restore', ['--execute' => true, '--document' => [$a->id]]);

        $this->assertSame(1, $one['counts']['restored']);
        $this->assertSame(1, $one['counts']['considered']);
        Storage::disk('local')->assertMissing($b->file_path);

        $this->makeStorageUnreachable();
        $blocked = $this->runCommand('hr:documents-restore', ['--execute' => true]);
        $this->assertSame('primary_unavailable', $blocked['aborted']);
        $this->assertSame(1, $blocked['_exit']);
    }

    public function test_a_restore_write_that_fails_leaves_no_partial_object_and_is_not_reported_as_restored(): void
    {
        $primary = $this->useObjectStore('hr_object_primary', new InMemoryObjectStore);
        $doc = $this->document('SAFE-IN-MIRROR');
        Storage::disk('local')->delete($doc->file_path);
        Storage::disk('hr_mirror')->put($doc->file_path, 'SAFE-IN-MIRROR');
        $doc->update(['disk' => 'hr_object_primary']);
        $primary->failNextWrites = 1;

        $report = $this->runCommand('hr:documents-restore', ['--execute' => true, '--primary-disk' => 'hr_object_primary']);

        $this->assertSame(0, $report['counts']['restored']);
        $this->assertSame(1, $report['counts']['restore_failed']);
        $this->assertSame([], $primary->objects);

        $this->assertSame(1, $this->runCommand('hr:documents-restore', ['--execute' => true, '--primary-disk' => 'hr_object_primary'])['counts']['restored']);
        $this->assertSame('SAFE-IN-MIRROR', $primary->objects[$doc->file_path]);
    }

    public function test_the_mirror_and_restore_runs_only_read_the_database(): void
    {
        $this->document('ONE');
        $statements = [];
        DB::listen(function ($query) use (&$statements): void {
            $statements[] = strtolower(ltrim($query->sql));
        });

        $this->runCommand('hr:documents-mirror');
        Storage::disk('local')->delete(EmployeeDocument::query()->first()->file_path);
        $this->runCommand('hr:documents-restore', ['--execute' => true]);

        $this->assertNotEmpty($statements);
        foreach ($statements as $sql) {
            $this->assertStringStartsWith('select', $sql, 'The mirror and restore commands must run SELECT statements only.');
        }
    }

    public function test_the_mirror_code_cannot_delete_move_overwrite_or_change_records_by_construction(): void
    {
        $files = [
            app_path('Support/Hr/Documents/DocumentMirror.php'),
            app_path('Console/Commands/MirrorEmployeeDocumentsCommand.php'),
            app_path('Console/Commands/RestoreEmployeeDocumentsCommand.php'),
            app_path('Console/Commands/CheckEmployeeDocumentMirrorCommand.php'),
        ];
        $source = implode("\n", array_map(fn (string $f) => (string) file_get_contents($f), $files));

        // Exactly one delete in all of the mirror code: the removal of an object this run itself just wrote and failed to verify.
        $this->assertSame(1, substr_count($source, '->delete('));
        $this->assertStringContainsString('private function discardPartial', (string) file_get_contents($files[0]));
        foreach (['deleteDirectory', '->move(', '->copy(', 'unlink(', 'rmdir(', '->update(', '->save(', '->insert(', 'forceFill', 'DB::', 'Model::destroy', '->forceDelete('] as $needle) {
            $this->assertStringNotContainsString($needle, $source, "The mirror code must not contain {$needle}.");
        }
    }
}
