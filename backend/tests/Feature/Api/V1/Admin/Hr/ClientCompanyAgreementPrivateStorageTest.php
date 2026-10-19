<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompanyAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Tests\Support\FakesDocumentDisk;
use Tests\Support\HrFixtures;
use Tests\Support\InMemoryObjectStore;
use Tests\TestCase;

/**
 * P0.2 - company agreements are kept on private, durable storage with the same verified write as employee documents:
 * the stored size and SHA-256 are proven before any row exists, a download proves the bytes again, a failed or unverifiable
 * write saves nothing, and a storage that is missing or not durable in production is refused instead of silently replaced
 * by the application's own (ephemeral) disk. Isolated fake disks and an in-memory object store only - no bucket,
 * volume or credential.
 */
class ClientCompanyAgreementPrivateStorageTest extends TestCase
{
    use FakesDocumentDisk;
    use HrFixtures;
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n%agreement body for the test\n";

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function objectDisk(): InMemoryObjectStore
    {
        $this->fakeLocalDisk();
        $store = $this->useObjectStore('agreements_object', new InMemoryObjectStore);
        config(['filesystems.hr_attachments_disk' => 'agreements_object']);

        return $store;
    }

    private function upload(string $token, int $companyId, ?UploadedFile $file = null)
    {
        return $this->asToken($token)->post("/api/v1/admin/hr/client-companies/{$companyId}/agreements", [
            'file' => $file ?? UploadedFile::fake()->createWithContent('agreement.pdf', self::PDF),
            'notes' => 'Signed copy',
        ], ['Accept' => 'application/json']);
    }

    private function realFile(string $name, string $content): UploadedFile
    {
        $path = tempnam(sys_get_temp_dir(), 'agr');
        file_put_contents($path, $content);
        $this->beforeApplicationDestroyed(static fn () => @unlink($path));

        return new UploadedFile($path, $name, null, null, true);
    }

    // ------------------------------------------------------------------ upload

    public function test_an_agreement_is_written_verified_and_recorded_with_its_disk_and_checksum(): void
    {
        $store = $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $response = $this->upload($token, $company->id)->assertCreated();

        $row = ClientCompanyAgreement::query()->findOrFail($response->json('data.id'));
        $this->assertSame('agreements_object', $row->disk);
        $this->assertSame(hash('sha256', self::PDF), $row->sha256);
        $this->assertSame(strlen(self::PDF), (int) $row->file_size);
        $this->assertMatchesRegularExpression('#^client-companies/'.$company->id.'/agreements/[A-Za-z0-9._-]+$#', $row->file_path);
        $this->assertSame(self::PDF, $store->objects[$row->file_path], 'The object holds exactly the uploaded bytes.');

        // The response never reveals where or how the file is stored.
        $this->assertSame(
            ['client_company_id', 'file_name', 'file_size', 'file_type', 'id', 'notes', 'uploaded_at', 'uploaded_by'],
            collect($response->json('data'))->keys()->sort()->values()->all(),
        );
    }

    public function test_the_stored_file_name_is_cleaned_of_folders_and_control_characters(): void
    {
        $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $file = UploadedFile::fake()->createWithContent("..\\..\\evil/folder\x07/contract.pdf", self::PDF);
        $row = ClientCompanyAgreement::query()->findOrFail($this->upload($token, $company->id, $file)->assertCreated()->json('data.id'));

        $this->assertSame('contract.pdf', $row->file_name);
        $this->assertStringNotContainsString('..', $row->file_path);
    }

    public function test_disallowed_and_oversized_files_are_refused_and_nothing_is_stored(): void
    {
        $store = $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $this->upload($token, $company->id, UploadedFile::fake()->createWithContent('virus.exe', "MZ\x90\x00 not a document"))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        // A real file (not the fake that reports its type from its name): the rule sniffs the CONTENT, so text named .pdf is refused.
        $this->upload($token, $company->id, $this->realFile('notes.pdf', 'plain text pretending to be a pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->upload($token, $company->id, UploadedFile::fake()->create('big.pdf', 10241, 'application/pdf'))
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $this->asToken($token)->postJson("/api/v1/admin/hr/client-companies/{$company->id}/agreements", [])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertSame([], $store->objects);
        $this->assertSame([], $store->writes);
        $this->assertDatabaseCount('client_company_agreements', 0);
    }

    public function test_a_write_cut_short_saves_nothing_and_leaves_no_object(): void
    {
        $store = $this->objectDisk();
        $store->failNextWrites = 1;
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $this->upload($token, $company->id)->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');

        $this->assertSame([], $store->objects, 'The partial object the failed write left behind must be removed.');
        $this->assertDatabaseCount('client_company_agreements', 0);
    }

    public function test_a_silently_corrupted_write_is_caught_by_the_read_back(): void
    {
        $store = $this->objectDisk();
        $store->corruptNextWrites = 1;
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $this->upload($token, $company->id)->assertStatus(503);

        $this->assertSame([], $store->objects);
        $this->assertDatabaseCount('client_company_agreements', 0);

        // Once the store behaves, the very same upload succeeds.
        $this->upload($token, $company->id)->assertCreated();
        $this->assertCount(1, $store->objects);
    }

    public function test_an_unreachable_storage_answers_503_and_saves_nothing(): void
    {
        $store = $this->objectDisk();
        $store->down = true;
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $this->upload($token, $company->id)->assertStatus(503)->assertHeader('Retry-After', '60');

        $this->assertDatabaseCount('client_company_agreements', 0);
    }

    public function test_a_database_failure_after_the_write_removes_the_object_it_just_wrote(): void
    {
        $store = $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        ClientCompanyAgreement::creating(static function (): never {
            throw new \RuntimeException('simulated database failure');
        });

        $this->asToken($token)->withoutExceptionHandling();
        try {
            $this->upload($token, $company->id);
            $this->fail('The simulated database failure should have surfaced.');
        } catch (\RuntimeException $e) {
            $this->assertSame('simulated database failure', $e->getMessage());
        }

        $this->assertSame([], $store->objects, 'The object written before the failed save must not be left behind.');
        $this->assertDatabaseCount('client_company_agreements', 0);
    }

    // ------------------------------------------------- misconfigured storage

    public function test_in_production_the_application_local_disk_is_refused_not_used(): void
    {
        $this->fakeLocalDisk();
        config(['filesystems.hr_attachments_disk' => 'local']);
        $this->app->detectEnvironment(static fn (): string => 'production');
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $this->upload($token, $company->id)->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');

        $this->assertDatabaseCount('client_company_agreements', 0);
        $this->assertSame([], glob(storage_path('framework/testing/disks/local/client-companies/*')) ?: [], 'Nothing was written to the local disk.');
    }

    public function test_an_unknown_or_empty_disk_name_is_refused_and_never_replaced_by_local(): void
    {
        $this->fakeLocalDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        foreach (['does-not-exist', ''] as $name) {
            config(['filesystems.hr_attachments_disk' => $name]);
            $this->upload($token, $company->id)->assertStatus(503)->assertJsonPath('error_code', 'DOCUMENT_STORAGE_UNAVAILABLE');
        }

        $this->assertDatabaseCount('client_company_agreements', 0);
        $this->assertSame([], glob(storage_path('framework/testing/disks/local/client-companies/*')) ?: []);
    }

    // ---------------------------------------------------------------- download

    public function test_an_authorized_download_streams_the_exact_bytes_privately(): void
    {
        $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $id = $this->upload($token, $company->id)->assertCreated()->json('data.id');

        $response = $this->asToken($token)->get("/api/v1/admin/hr/client-companies/{$company->id}/agreements/{$id}/download")
            ->assertOk()->assertDownload('agreement.pdf')
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertHeader('X-Content-Type-Options', 'nosniff');

        $this->assertSame(self::PDF, $response->streamedContent());
    }

    public function test_download_needs_authentication_and_hr_view_and_the_right_company(): void
    {
        $this->objectDisk();
        [, $superToken] = $this->superAdminToken();
        $a = $this->makeCompany('A Co');
        $b = $this->makeCompany('B Co');
        $id = $this->upload($superToken, $a->id)->assertCreated()->json('data.id');
        $url = fn (int $company, int $agreement) => "/api/v1/admin/hr/client-companies/{$company}/agreements/{$agreement}/download";

        $this->app['auth']->forgetGuards();
        $this->withHeaders(['Authorization' => ''])->getJson($url($a->id, $id))->assertStatus(401);
        $this->asToken($this->noPermissionToken())->getJson($url($a->id, $id))->assertStatus(403);
        $this->asToken($this->tokenWithOnly(['hr.view']))->get($url($a->id, $id))->assertOk(); // viewing is enough to read
        $this->asToken($superToken)->getJson($url($b->id, $id))->assertStatus(404)->assertJsonPath('error_code', 'AGREEMENT_NOT_FOUND');
        $this->asToken($superToken)->getJson($url($a->id, 999999))->assertStatus(404);
    }

    public function test_a_missing_file_a_damaged_file_and_an_outage_are_answered_plainly_and_never_served(): void
    {
        $store = $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $id = $this->upload($token, $company->id)->assertCreated()->json('data.id');
        $row = ClientCompanyAgreement::query()->findOrFail($id);
        $url = "/api/v1/admin/hr/client-companies/{$company->id}/agreements/{$id}/download";

        // damaged: the stored bytes no longer match the recorded checksum
        $store->objects[$row->file_path] = 'TAMPERED-CONTENT';
        $this->asToken($token)->getJson($url)->assertStatus(502)->assertJsonPath('error_code', 'AGREEMENT_INTEGRITY_FAILED');

        // missing
        unset($store->objects[$row->file_path]);
        $this->asToken($token)->getJson($url)->assertStatus(404)->assertJsonPath('error_code', 'AGREEMENT_FILE_MISSING');

        // storage outage
        $store->objects[$row->file_path] = self::PDF;
        $store->down = true;
        $this->asToken($token)->getJson($url)->assertStatus(503)->assertJsonPath('error_code', 'AGREEMENT_STORAGE_UNAVAILABLE');
    }

    public function test_an_older_agreement_without_a_checksum_is_still_served(): void
    {
        $store = $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $id = $this->upload($token, $company->id)->assertCreated()->json('data.id');
        ClientCompanyAgreement::query()->whereKey($id)->update(['sha256' => null]);

        $this->asToken($token)->get("/api/v1/admin/hr/client-companies/{$company->id}/agreements/{$id}/download")->assertOk();
        $this->assertNotEmpty($store->objects);
    }

    // ---------------------------------------------------------------- deletion

    public function test_a_company_that_has_agreements_cannot_be_deleted_and_its_files_are_untouched(): void
    {
        $store = $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $id = $this->upload($token, $company->id)->assertCreated()->json('data.id');
        $path = ClientCompanyAgreement::query()->findOrFail($id)->file_path;

        $this->asToken($token)->deleteJson("/api/v1/admin/hr/client-companies/{$company->id}")
            ->assertStatus(422)->assertJsonPath('error_code', 'CLIENT_COMPANY_HAS_AGREEMENTS');

        $this->assertDatabaseHas('client_companies', ['id' => $company->id]);
        $this->assertDatabaseHas('client_company_agreements', ['id' => $id]);
        $this->assertSame(self::PDF, $store->objects[$path]);
        $this->assertSame([], $store->deleted, 'Nothing was removed from storage.');
    }

    public function test_there_is_no_route_that_deletes_an_agreement_file(): void
    {
        $this->objectDisk();
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $id = $this->upload($token, $company->id)->assertCreated()->json('data.id');

        $status = $this->asToken($token)->deleteJson("/api/v1/admin/hr/client-companies/{$company->id}/agreements/{$id}")->getStatusCode();
        $this->assertContains($status, [404, 405], 'No route deletes an agreement.');
        $this->assertDatabaseHas('client_company_agreements', ['id' => $id]);
    }
}
