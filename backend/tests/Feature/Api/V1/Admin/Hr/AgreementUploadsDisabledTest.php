<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompanyAgreement;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\FakesDocumentDisk;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Approved decision: agreement uploads stay disabled until the private bucket, the verified mirror and a restore drill have
 * passed. The switch is off by default (HR_AGREEMENT_UPLOADS_ENABLED); the test suite turns it on in phpunit.xml, so these
 * tests turn it off the way a real environment is configured and prove nothing is written.
 */
class AgreementUploadsDisabledTest extends TestCase
{
    use FakesDocumentDisk;
    use HrFixtures;
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n%agreement body for the test\n";

    private function upload(string $token, int $companyId)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token)->post("/api/v1/admin/hr/client-companies/{$companyId}/agreements", [
            'file' => UploadedFile::fake()->createWithContent('agreement.pdf', self::PDF),
        ], ['Accept' => 'application/json']);
    }

    public function test_the_switch_is_off_by_default(): void
    {
        // phpunit.xml switches uploads on for the suite, so the shipped defaults are read from their source files.
        $this->assertStringContainsString("env('HR_AGREEMENT_UPLOADS_ENABLED', false)", (string) file_get_contents(base_path('config/filesystems.php')));
        $this->assertMatchesRegularExpression('/^HR_AGREEMENT_UPLOADS_ENABLED=false$/m', (string) file_get_contents(base_path('.env.example')));
    }

    public function test_an_upload_is_refused_and_nothing_is_written_while_the_switch_is_off(): void
    {
        $this->fakeLocalDisk();
        config(['filesystems.hr_agreement_uploads_enabled' => false, 'filesystems.hr_attachments_disk' => 'local']);
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $this->upload($token, $company->id)
            ->assertForbidden()
            ->assertJsonPath('error_code', 'AGREEMENT_UPLOADS_DISABLED');

        $this->assertDatabaseCount('client_company_agreements', 0);
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_existing_agreements_stay_listed_and_downloadable_while_uploads_are_off(): void
    {
        $this->fakeLocalDisk();
        config(['filesystems.hr_agreement_uploads_enabled' => true, 'filesystems.hr_attachments_disk' => 'local']);
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();
        $id = $this->upload($token, $company->id)->assertCreated()->json('data.id');

        config(['filesystems.hr_agreement_uploads_enabled' => false]);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("/api/v1/admin/hr/client-companies/{$company->id}/agreements")->assertOk()->assertJsonCount(1, 'data');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->get("/api/v1/admin/hr/client-companies/{$company->id}/agreements/{$id}/download")->assertOk();

        $this->upload($token, $company->id)->assertForbidden();
        $this->assertSame(1, ClientCompanyAgreement::query()->count());
    }
}
