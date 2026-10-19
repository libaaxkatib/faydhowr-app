<?php

namespace Tests\Feature\Hr;

use App\Models\ClientCompany;
use App\Models\ClientCompanyAgreement;
use App\Support\Hr\Documents\DocumentMirror;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesDocumentDisk;
use Tests\TestCase;

/**
 * Client-company agreements ride the same verified recovery mirror as employee documents (approved decision: private storage
 * plus a verified mirror and a restore path). Same guarantees, proven for agreements: copy with SHA-256 read-back, idempotence,
 * deep verification catching a same-size substitution, a damaged source never becoming the backup, restore only into a confirmed
 * gap and only from a verified copy, nothing deleted or overwritten, nothing changed in the database. Fake disks only.
 */
class AgreementMirrorTest extends TestCase
{
    use FakesDocumentDisk;
    use RefreshDatabase;

    private ClientCompany $company;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fakeLocalDisk();
        $this->fakeDisk('hr_agreements');
        $this->fakeDisk('hr_mirror');
        config([
            'filesystems.hr_documents_disk' => 'local',
            'filesystems.hr_attachments_disk' => 'hr_agreements',
            'filesystems.hr_documents_mirror_disk' => 'hr_mirror',
        ]);
        $this->company = ClientCompany::query()->create(['name' => 'Mirror Probe Co', 'category' => 'company', 'status' => 'active']);
    }

    private function agreement(string $content, bool $withFile = true, bool $recordChecksum = true, ?string $recordedSha = null, string $disk = 'hr_agreements'): ClientCompanyAgreement
    {
        $path = "client-companies/{$this->company->id}/agreements/".bin2hex(random_bytes(8)).'.pdf';

        if ($withFile) {
            Storage::disk($disk)->put($path, $content);
        }

        return ClientCompanyAgreement::query()->create([
            'client_company_id' => $this->company->id, 'uploaded_by' => null, 'file_name' => 'private-contract.pdf', 'file_type' => 'pdf',
            'file_size' => strlen($content), 'disk' => $disk, 'file_path' => $path,
            'sha256' => $recordChecksum ? ($recordedSha ?? hash('sha256', $content)) : null,
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

    /** @return array<string, string> */
    private function snapshot(string $disk): array
    {
        $files = [];
        foreach (Storage::disk($disk)->allFiles() as $file) {
            $files[$file] = hash('sha256', Storage::disk($disk)->get($file));
        }
        ksort($files);

        return $files;
    }

    public function test_agreements_are_copied_verified_listed_in_the_manifest_and_a_second_run_changes_nothing(): void
    {
        $a = $this->agreement('CONTRACT-ONE');
        $b = $this->agreement('CONTRACT-TWO-LONGER');

        $first = $this->runCommand('hr:documents-mirror');

        $this->assertSame(0, $first['_exit']);
        $this->assertTrue($first['ok']);
        $this->assertSame(2, $first['counts']['copied']);
        $this->assertSame('CONTRACT-ONE', Storage::disk('hr_mirror')->get($a->file_path));
        $this->assertSame(hash('sha256', 'CONTRACT-TWO-LONGER'), hash('sha256', Storage::disk('hr_mirror')->get($b->file_path)));

        $manifest = json_decode(Storage::disk('hr_mirror')->get('_mirror/manifests/'.collect(Storage::disk('hr_mirror')->files('_mirror/manifests'))->map(fn ($f) => basename($f))->first()), true);
        $this->assertEqualsCanonicalizing([$a->id, $b->id], array_column($manifest['entries'], 'agreement_id'));
        $this->assertSame(['client_company_agreement'], array_values(array_unique(array_column($manifest['entries'], 'kind'))));

        $before = $this->snapshot('hr_mirror');
        $second = $this->runCommand('hr:documents-mirror');

        $this->assertSame(0, $second['counts']['copied']);
        $this->assertSame(2, $second['counts']['already_present']);
        $objects = fn (array $files) => array_filter($files, fn ($path) => str_starts_with($path, 'client-companies/'), ARRAY_FILTER_USE_KEY);
        $this->assertSame($objects($before), $objects($this->snapshot('hr_mirror')), 'a second run changes no mirrored object');
    }

    public function test_the_agreement_source_is_never_modified_or_deleted_and_the_database_is_not_changed(): void
    {
        $a = $this->agreement('CONTRACT-ONE');
        $sourceBefore = $this->snapshot('hr_agreements');
        $rowBefore = ClientCompanyAgreement::query()->find($a->id)->getAttributes();

        $this->runCommand('hr:documents-mirror');
        $this->runCommand('hr:documents-mirror', ['--deep' => true]);

        $this->assertSame($sourceBefore, $this->snapshot('hr_agreements'));
        $this->assertSame($rowBefore, ClientCompanyAgreement::query()->find($a->id)->getAttributes());
    }

    public function test_a_deep_run_catches_a_same_size_substitution_that_a_routine_run_accepts(): void
    {
        $a = $this->agreement('AAAAAAAA');
        $this->runCommand('hr:documents-mirror');
        Storage::disk('hr_mirror')->put($a->file_path, 'BBBBBBBB'); // same size, different content

        $routine = $this->runCommand('hr:documents-mirror');
        $this->assertSame(1, $routine['counts']['already_present']);

        $deep = $this->runCommand('hr:documents-mirror', ['--deep' => true]);
        $this->assertSame(1, $deep['counts']['mirror_conflict']);
        $this->assertSame(1, $deep['_exit']);
        $this->assertSame('BBBBBBBB', Storage::disk('hr_mirror')->get($a->file_path), 'the conflicting object is reported, never overwritten');
        $this->assertSame($a->id, $deep['failures'][0]['agreement_id']);
    }

    public function test_a_damaged_agreement_is_never_copied_as_a_backup(): void
    {
        $this->agreement('GOOD-CONTENT', recordedSha: hash('sha256', 'SOMETHING-ELSE'));

        $report = $this->runCommand('hr:documents-mirror');

        $this->assertSame(1, $report['counts']['source_checksum_mismatch']);
        $this->assertSame(0, $report['counts']['copied']);
        $this->assertSame([], array_filter(array_keys($this->snapshot('hr_mirror')), fn ($p) => str_starts_with($p, 'client-companies/')));
    }

    public function test_a_missing_agreement_file_is_reported_not_hidden(): void
    {
        $this->agreement('X', withFile: false);

        $report = $this->runCommand('hr:documents-mirror');

        $this->assertSame(1, $report['counts']['source_missing']);
        $this->assertFalse($report['ok']);
    }

    public function test_restore_drill_recovers_a_lost_agreement_from_the_verified_mirror_only_into_the_gap(): void
    {
        $lost = $this->agreement('CONTRACT-LOST');
        $kept = $this->agreement('CONTRACT-KEPT');
        $this->runCommand('hr:documents-mirror');
        Storage::disk('hr_agreements')->delete($lost->file_path); // the disaster

        $report = $this->runCommand('hr:documents-restore'); // report only
        $this->assertSame(1, $report['counts']['restorable']);
        $this->assertSame(1, $report['counts']['present']);
        $this->assertFalse(Storage::disk('hr_agreements')->exists($lost->file_path), 'a report-only run restores nothing');

        $kept->refresh();
        Storage::disk('hr_agreements')->put($kept->file_path, 'CONTRACT-KEPT'); // untouched, same content

        $done = $this->runCommand('hr:documents-restore', ['--execute' => true]);
        $this->assertSame(1, $done['counts']['restored']);
        $this->assertSame(0, $done['_exit']);
        $this->assertSame(hash('sha256', 'CONTRACT-LOST'), hash('sha256', Storage::disk('hr_agreements')->get($lost->file_path)));
        $this->assertSame(hash('sha256', 'CONTRACT-LOST'), $lost->fresh()->sha256, 'the recorded checksum is untouched');

        $again = $this->runCommand('hr:documents-restore', ['--execute' => true]);
        $this->assertSame(0, $again['counts']['restored']);
        $this->assertSame(2, $again['counts']['present']);
    }

    public function test_restore_refuses_a_mirror_copy_that_does_not_match_the_recorded_checksum(): void
    {
        $a = $this->agreement('ORIGINAL');
        $this->runCommand('hr:documents-mirror');
        Storage::disk('hr_agreements')->delete($a->file_path);
        Storage::disk('hr_mirror')->put($a->file_path, 'TAMPERED'); // the mirror copy is wrong

        $report = $this->runCommand('hr:documents-restore', ['--execute' => true]);

        $this->assertSame(1, $report['counts']['mirror_checksum_mismatch']);
        $this->assertSame(0, $report['counts']['restored']);
        $this->assertFalse(Storage::disk('hr_agreements')->exists($a->file_path));
        $this->assertSame(1, $report['_exit']);
    }

    public function test_restore_never_restores_what_it_cannot_verify_and_says_so(): void
    {
        $noChecksum = $this->agreement('NO-CHECKSUM', recordChecksum: false);
        $this->runCommand('hr:documents-mirror');
        Storage::disk('hr_agreements')->delete($noChecksum->file_path);

        $report = $this->runCommand('hr:documents-restore', ['--execute' => true]);

        $this->assertSame(1, $report['counts']['unverifiable_no_recorded_checksum']);
        $this->assertSame(0, $report['counts']['restored']);
        $this->assertFalse(Storage::disk('hr_agreements')->exists($noChecksum->file_path));

        $notMirrored = $this->agreement('NEVER-MIRRORED');
        Storage::disk('hr_agreements')->delete($notMirrored->file_path);
        $report = $this->runCommand('hr:documents-restore', ['--execute' => true, '--agreement' => [$notMirrored->id]]);
        $this->assertSame(1, $report['counts']['not_in_mirror']);
    }

    public function test_restricting_to_documents_leaves_agreements_alone_and_the_agreement_option_selects_one(): void
    {
        $one = $this->agreement('ONE');
        $two = $this->agreement('TWO');
        $this->runCommand('hr:documents-mirror');
        Storage::disk('hr_agreements')->delete($one->file_path);
        Storage::disk('hr_agreements')->delete($two->file_path);

        $documentsOnly = $this->runCommand('hr:documents-restore', ['--execute' => true, '--document' => [999999]]);
        $this->assertSame(0, $documentsOnly['counts']['considered']);

        $picked = $this->runCommand('hr:documents-restore', ['--execute' => true, '--agreement' => [$two->id]]);
        $this->assertSame(1, $picked['counts']['restored']);
        $this->assertTrue(Storage::disk('hr_agreements')->exists($two->file_path));
        $this->assertFalse(Storage::disk('hr_agreements')->exists($one->file_path));
    }

    public function test_agreements_on_a_disk_other_than_the_configured_one_are_not_this_mirrors_business(): void
    {
        $this->agreement('ELSEWHERE', disk: 'local');

        $report = $this->runCommand('hr:documents-mirror');

        $this->assertSame(0, $report['counts']['considered']);
    }

    public function test_the_mirror_cannot_be_the_agreements_disk(): void
    {
        $this->agreement('X');

        $report = $this->runCommand('hr:documents-mirror', ['--agreements-disk' => 'hr_mirror']);

        $this->assertSame('mirror_is_the_primary_disk', $report['aborted']);
        $this->assertSame(0, $report['counts']['copied']);
    }

    public function test_the_freshness_check_covers_a_run_that_included_agreements(): void
    {
        $this->agreement('FRESH');
        $this->runCommand('hr:documents-mirror');

        $check = $this->runCommand('hr:documents-mirror-check');

        $this->assertTrue($check['ok']);
        $this->assertSame(0, $check['_exit']);
    }

    public function test_a_database_permission_error_is_named_as_such_and_other_errors_keep_their_reason(): void
    {
        $denied = new QueryException('pgsql', 'select 1', [], new \PDOException('permission denied for table client_company_agreements', 0));
        $ref = new \ReflectionProperty(\Exception::class, 'code');
        $ref->setValue($denied, '42501');

        $this->assertSame('database_permission_denied', DocumentMirror::abortReasonFor($denied, 'mirror_unavailable'));
        $this->assertSame('mirror_unavailable', DocumentMirror::abortReasonFor(new \RuntimeException('x'), 'mirror_unavailable'));
    }
}
