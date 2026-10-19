<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\EmployeeDocument;
use App\Models\EmployeeDocumentCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakesDocumentDisk;
use Tests\Support\HrFixtures;
use Tests\Support\InMemoryObjectStore;
use Tests\TestCase;

/**
 * P2.1 - correcting the category of an existing employee document. One column on the existing row changes; no row is
 * added, no file is rewritten or even contacted, and the checksum, disk, path, size and verification state stay exactly as
 * they were. The move never supersedes another document and never crosses employees.
 */
class EmployeeDocumentCategoryUpdateTest extends TestCase
{
    use FakesDocumentDisk;
    use HrFixtures;
    use RefreshDatabase;

    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function category(string $name): EmployeeDocumentCategory
    {
        return EmployeeDocumentCategory::query()->firstOrCreate(['name' => $name]);
    }

    private function document(int $employeeId, ?int $categoryId, array $overrides = []): EmployeeDocument
    {
        return EmployeeDocument::query()->create($overrides + [
            'employee_id' => $employeeId,
            'admin_id' => Admin::query()->value('id') ?? Admin::factory()->superAdmin()->create()->id,
            'employee_document_category_id' => $categoryId,
            'file_name' => 'scan.pdf',
            'file_type' => 'pdf',
            'file_size' => 1234,
            'file_path' => 'employees/'.$employeeId.'/documents/'.fake()->unique()->lexify('????????????').'.pdf',
            'disk' => 'r2_documents',
            'sha256' => hash('sha256', fake()->unique()->sentence()),
            'verification_status' => 'pending',
            'is_current' => true,
        ]);
    }

    private function url(int $employeeId, int $documentId): string
    {
        return "/api/v1/admin/hr/employees/{$employeeId}/documents/{$documentId}/category";
    }

    public function test_the_category_of_an_existing_document_is_corrected_in_place(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee('inactive'); // an inactive employee is no obstacle
        $guarantor = $this->category('Guarantor ID');
        $document = $this->document($employee->id, null, ['verification_status' => 'verified', 'document_number' => 'AB-123']);
        $before = $document->fresh()->only(['disk', 'file_path', 'file_size', 'file_type', 'sha256', 'verification_status', 'document_number', 'file_name', 'is_current', 'verified_at', 'expiry_date']);
        $rowsBefore = EmployeeDocument::query()->count();

        $this->asToken($token)->patchJson($this->url($employee->id, $document->id), ['employee_document_category_id' => $guarantor->id])
            ->assertOk()
            ->assertJsonPath('data.id', $document->id)
            ->assertJsonPath('data.employee_document_category_id', $guarantor->id)
            ->assertJsonPath('data.category_name', 'Guarantor ID')
            ->assertJsonMissingPath('data.file_path')
            ->assertJsonMissingPath('data.sha256');

        $after = $document->fresh();
        $this->assertSame($guarantor->id, $after->employee_document_category_id);
        $this->assertSame($before, $after->only(array_keys($before)), 'Nothing but the category changed.');
        $this->assertSame($rowsBefore, EmployeeDocument::query()->count(), 'No document row was added.');
        $this->assertSame(1, AuditLog::query()->where('entity_type', EmployeeDocument::class)->where('entity_id', $document->id)->where('description', 'like', '%category%')->count());
    }

    public function test_the_move_never_touches_storage(): void
    {
        $this->fakeLocalDisk();
        $store = $this->useObjectStore('r2_documents', new InMemoryObjectStore);
        $store->down = true; // an outage must not matter: the file is neither read nor written
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $document = $this->document($employee->id, null);

        $this->asToken($token)->patchJson($this->url($employee->id, $document->id), ['employee_document_category_id' => $this->category('Contract')->id])->assertOk();

        $this->assertSame([], $store->writes);
        $this->assertSame([], $store->deleted);
        $this->assertSame(0, $store->reads);
    }

    public function test_choosing_the_same_category_again_changes_nothing_and_records_nothing(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $category = $this->category('Identity');
        $document = $this->document($employee->id, $category->id);
        $audits = AuditLog::query()->count();

        $this->asToken($token)->patchJson($this->url($employee->id, $document->id), ['employee_document_category_id' => $category->id])->assertOk();

        $this->assertSame($audits, AuditLog::query()->count());
    }

    public function test_an_unknown_or_missing_category_is_refused(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $document = $this->document($employee->id, null);

        $this->asToken($token)->patchJson($this->url($employee->id, $document->id), ['employee_document_category_id' => 999999])
            ->assertStatus(422)->assertJsonValidationErrors('employee_document_category_id');
        $this->asToken($token)->patchJson($this->url($employee->id, $document->id), [])
            ->assertStatus(422)->assertJsonValidationErrors('employee_document_category_id');
        $this->asToken($token)->patchJson($this->url($employee->id, $document->id), ['employee_document_category_id' => 'abc'])
            ->assertStatus(422)->assertJsonValidationErrors('employee_document_category_id');

        $this->assertNull($document->fresh()->employee_document_category_id);
    }

    public function test_it_needs_authentication_and_hr_manage(): void
    {
        $employee = $this->makeEmployee();
        $document = $this->document($employee->id, null);
        $payload = ['employee_document_category_id' => $this->category('Other')->id];

        $this->app['auth']->forgetGuards();
        $this->patchJson($this->url($employee->id, $document->id), $payload)->assertStatus(401);
        $this->asToken($this->noPermissionToken())->patchJson($this->url($employee->id, $document->id), $payload)->assertStatus(403);
        $this->asToken($this->tokenWithOnly(['hr.view']))->patchJson($this->url($employee->id, $document->id), $payload)->assertStatus(403);

        $this->assertNull($document->fresh()->employee_document_category_id);

        $this->asToken($this->tokenWithOnly(['hr.manage']))->patchJson($this->url($employee->id, $document->id), $payload)->assertOk();
    }

    public function test_a_document_of_another_employee_or_a_missing_one_is_not_found(): void
    {
        [, $token] = $this->superAdminToken();
        $owner = $this->makeEmployee();
        $other = $this->makeEmployee();
        $document = $this->document($owner->id, null);
        $payload = ['employee_document_category_id' => $this->category('Other')->id];

        $this->asToken($token)->patchJson($this->url($other->id, $document->id), $payload)
            ->assertStatus(404)->assertJsonPath('error_code', 'EMPLOYEE_DOCUMENT_NOT_FOUND');
        $this->asToken($token)->patchJson($this->url($owner->id, 999999), $payload)->assertStatus(404);
        $this->asToken($token)->patchJson($this->url(999999, $document->id), $payload)->assertStatus(404);

        $this->assertNull($document->fresh()->employee_document_category_id);
    }

    public function test_a_category_that_already_has_a_current_document_is_not_taken_over(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $category = $this->category('Contract copy');
        $existing = $this->document($employee->id, $category->id);
        $loose = $this->document($employee->id, null);

        $this->asToken($token)->patchJson($this->url($employee->id, $loose->id), ['employee_document_category_id' => $category->id])
            ->assertStatus(422)->assertJsonPath('error_code', 'DOCUMENT_CATEGORY_OCCUPIED');

        $this->assertNull($loose->fresh()->employee_document_category_id);
        $this->assertTrue($existing->fresh()->is_current, 'The other document was not superseded.');
        $this->assertNull($existing->fresh()->superseded_by_document_id);
    }

    public function test_only_the_current_document_and_never_the_profile_picture_can_be_moved(): void
    {
        [, $token] = $this->superAdminToken();
        $employee = $this->makeEmployee();
        $old = $this->document($employee->id, $this->category('Passport')->id, ['is_current' => false]);
        $picture = $this->document($employee->id, null);
        $employee->update(['profile_picture_document_id' => $picture->id]);
        $payload = ['employee_document_category_id' => $this->category('Other')->id];

        $this->asToken($token)->patchJson($this->url($employee->id, $old->id), $payload)
            ->assertStatus(422)->assertJsonPath('error_code', 'DOCUMENT_NOT_CURRENT');
        $this->asToken($token)->patchJson($this->url($employee->id, $picture->id), $payload)
            ->assertStatus(422)->assertJsonPath('error_code', 'DOCUMENT_IS_PROFILE_PICTURE');

        $this->assertNull($picture->fresh()->employee_document_category_id);
    }
}
