<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Models\ClientCompanyAgreement;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class ClientCompanyCategoryAndAgreementTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    public function test_a_company_defaults_to_the_company_category_and_a_household_can_be_created_as_guri(): void
    {
        [, $token] = $this->superAdminToken();

        $this->withToken($token)->postJson('/api/v1/admin/hr/client-companies', ['name' => 'Alpha Hospital'])
            ->assertCreated()->assertJsonPath('data.category', 'company');
        $this->withToken($token)->postJson('/api/v1/admin/hr/client-companies', ['name' => 'GURI TEST', 'category' => 'guri'])
            ->assertCreated()->assertJsonPath('data.category', 'guri')->assertJsonPath('data.category_label', 'Guri (household)');
    }

    public function test_the_category_can_be_corrected_without_recreating_the_record(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('Kinsi', 'guri');

        $this->withToken($token)->putJson("/api/v1/admin/hr/client-companies/{$company->id}", ['category' => 'company'])
            ->assertOk()->assertJsonPath('data.id', $company->id)->assertJsonPath('data.category', 'company');
        $this->assertDatabaseCount('client_companies', 1);
    }

    public function test_an_unknown_category_is_rejected(): void
    {
        [, $token] = $this->superAdminToken();

        $this->withToken($token)->postJson('/api/v1/admin/hr/client-companies', ['name' => 'X Co', 'category' => 'other'])
            ->assertStatus(422)->assertJsonValidationErrors('category');
    }

    public function test_case_only_duplicate_names_are_rejected_on_create_and_rename(): void
    {
        [, $token] = $this->superAdminToken();
        $this->makeCompany('TransitHotel');
        $other = $this->makeCompany('Other Co');

        $this->withToken($token)->postJson('/api/v1/admin/hr/client-companies', ['name' => 'TRANSITHOTEL'])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->withToken($token)->postJson('/api/v1/admin/hr/client-companies', ['name' => '  transithotel  '])
            ->assertStatus(422)->assertJsonValidationErrors('name');
        $this->withToken($token)->putJson("/api/v1/admin/hr/client-companies/{$other->id}", ['name' => 'transitHOTEL'])
            ->assertStatus(422)->assertJsonValidationErrors('name');

        $this->assertDatabaseCount('client_companies', 2);
    }

    public function test_a_company_may_change_only_the_letter_case_of_its_own_name(): void
    {
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany('MARWAFERTILITY');

        $this->withToken($token)->putJson("/api/v1/admin/hr/client-companies/{$company->id}", ['name' => 'MarwaFertility'])
            ->assertOk()->assertJsonPath('data.name', 'MarwaFertility');
    }

    public function test_the_database_itself_refuses_a_case_only_duplicate(): void
    {
        $this->makeCompany('TransitHotel');

        $this->expectException(QueryException::class);

        ClientCompany::query()->create(['name' => 'TRANSITHOTEL', 'status' => 'active']);
    }

    public function test_an_agreement_can_be_uploaded_listed_replaced_and_downloaded(): void
    {
        Storage::fake('local');
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $first = $this->withToken($token)->post("/api/v1/admin/hr/client-companies/{$company->id}/agreements", [
            'file' => UploadedFile::fake()->create('agreement-v1.pdf', 120, 'application/pdf'),
            'notes' => 'Signed copy',
        ], ['Accept' => 'application/json'])->assertCreated();

        $second = $this->withToken($token)->post("/api/v1/admin/hr/client-companies/{$company->id}/agreements", [
            'file' => UploadedFile::fake()->create('agreement-v2.pdf', 130, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $list = $this->withToken($token)->getJson("/api/v1/admin/hr/client-companies/{$company->id}/agreements")->assertOk();
        $this->assertSame(['agreement-v2.pdf', 'agreement-v1.pdf'], array_column($list->json('data'), 'file_name'));
        $this->assertDatabaseCount('client_company_agreements', 2); // replacement keeps history

        $row = ClientCompanyAgreement::query()->findOrFail($first->json('data.id'));
        Storage::disk('local')->assertExists($row->file_path);

        $this->withToken($token)->get("/api/v1/admin/hr/client-companies/{$company->id}/agreements/{$second->json('data.id')}/download")
            ->assertOk()->assertDownload('agreement-v2.pdf')
            ->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_the_attachment_disk_is_configurable_and_recorded_on_the_row(): void
    {
        Storage::fake('agreements_test');
        config(['filesystems.hr_attachments_disk' => 'agreements_test']);
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $response = $this->withToken($token)->post("/api/v1/admin/hr/client-companies/{$company->id}/agreements", [
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $row = ClientCompanyAgreement::query()->findOrFail($response->json('data.id'));
        $this->assertSame('agreements_test', $row->disk);
        Storage::disk('agreements_test')->assertExists($row->file_path);
    }

    public function test_disallowed_file_types_are_rejected(): void
    {
        Storage::fake('local');
        [, $token] = $this->superAdminToken();
        $company = $this->makeCompany();

        $this->withToken($token)->post("/api/v1/admin/hr/client-companies/{$company->id}/agreements", [
            'file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload'),
        ], ['Accept' => 'application/json'])->assertStatus(422)->assertJsonValidationErrors('file');
    }

    public function test_viewing_needs_hr_view_and_uploading_needs_hr_manage(): void
    {
        Storage::fake('local');
        $company = $this->makeCompany();
        $viewer = $this->tokenWithOnly(['hr.view']);

        $this->withToken($viewer)->getJson("/api/v1/admin/hr/client-companies/{$company->id}/agreements")->assertOk();
        $this->withToken($viewer)->post("/api/v1/admin/hr/client-companies/{$company->id}/agreements", [
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertForbidden();
        $this->assertDatabaseCount('client_company_agreements', 0);
    }

    public function test_an_admin_without_hr_access_cannot_even_list_agreements(): void
    {
        $company = $this->makeCompany();

        $this->withToken($this->noPermissionToken())->getJson("/api/v1/admin/hr/client-companies/{$company->id}/agreements")->assertForbidden();
    }

    public function test_an_agreement_of_another_company_cannot_be_downloaded_through_this_one(): void
    {
        Storage::fake('local');
        [, $token] = $this->superAdminToken();
        $a = $this->makeCompany('A Co');
        $b = $this->makeCompany('B Co');
        $upload = $this->withToken($token)->post("/api/v1/admin/hr/client-companies/{$a->id}/agreements", [
            'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->withToken($token)->getJson("/api/v1/admin/hr/client-companies/{$b->id}/agreements/{$upload->json('data.id')}/download")
            ->assertNotFound();
    }
}
