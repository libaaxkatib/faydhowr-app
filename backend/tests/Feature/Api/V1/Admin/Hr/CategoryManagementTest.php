<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\AuditLog;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\WorkforceRequest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Category management: create/rename (ids never change), delete only when nothing at all points at the category, archive when something
 * does (the records keep it, new registrations cannot pick it), restore, and the Office Staff category stays untouchable.
 */
class CategoryManagementTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/employee-categories';

    private string $manager;

    private string $viewer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function category(string $name): EmployeeCategory
    {
        return EmployeeCategory::query()->create(['name' => $name]);
    }

    public function test_the_list_shows_usage_and_archive_state_and_create_and_rename_are_validated(): void
    {
        $used = $this->category('Gardening');
        $this->makeEmployee()->update(['employee_category_id' => $used->id]);

        $row = collect($this->as($this->viewer)->getJson(self::URL)->assertOk()->json('data'))->firstWhere('id', $used->id);
        $this->assertSame([1, false, false], [$row['employees_count'], $row['archived'], $row['is_system']]);

        $this->as($this->manager)->postJson(self::URL, ['name' => '  Night   Guards '])->assertCreated()->assertJsonPath('data.name', 'Night Guards');
        $this->as($this->manager)->postJson(self::URL, ['name' => 'night guards'])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        $this->as($this->manager)->putJson(self::URL."/{$used->id}", ['name' => 'Garden Care'])->assertOk()->assertJsonPath('data.id', $used->id);
        $this->assertSame($used->id, Employee::query()->first()->employee_category_id, 'the employee keeps the same category after a rename');
    }

    public function test_an_unused_category_can_be_deleted_and_the_deletion_is_audited(): void
    {
        $unused = $this->category('Temporary pilot');

        $this->as($this->manager)->deleteJson(self::URL."/{$unused->id}")->assertOk();

        $this->assertDatabaseMissing('employee_categories', ['id' => $unused->id]);
        $this->assertTrue(AuditLog::query()->where('entity_type', EmployeeCategory::class)->where('entity_id', $unused->id)->where('description', 'like', '%deleted%')->exists());
    }

    public function test_a_category_that_anything_uses_is_never_deleted_and_the_answer_points_at_archiving(): void
    {
        $byEmployee = $this->category('Drivers');
        $this->makeEmployee()->update(['employee_category_id' => $byEmployee->id]);
        $r = $this->as($this->manager)->deleteJson(self::URL."/{$byEmployee->id}")->assertUnprocessable()->assertJsonPath('error_code', 'CATEGORY_IN_USE');
        $this->assertStringContainsString('1 employee', $r->json('message'));
        $this->assertStringContainsString('Archive it instead', $r->json('message'));

        // An archived (soft-deleted) employee is history and still counts.
        $byArchived = $this->category('Cooks');
        $gone = $this->makeEmployee();
        $gone->update(['employee_category_id' => $byArchived->id]);
        $gone->delete();
        $this->as($this->manager)->deleteJson(self::URL."/{$byArchived->id}")->assertUnprocessable()->assertJsonPath('error_code', 'CATEGORY_IN_USE');

        $byRequest = $this->category('Security');
        $company = $this->makeCompany();
        WorkforceRequest::query()->create(['client_company_id' => $company->id, 'employee_category_id' => $byRequest->id, 'quantity_needed' => 1, 'requested_date' => '2026-10-01', 'status' => 'open']);
        $this->as($this->manager)->deleteJson(self::URL."/{$byRequest->id}")->assertUnprocessable()->assertJsonPath('error_code', 'CATEGORY_IN_USE');

        foreach ([$byEmployee, $byArchived, $byRequest] as $c) {
            $this->assertDatabaseHas('employee_categories', ['id' => $c->id]);
        }
        $this->assertSame($byEmployee->id, Employee::query()->where('employee_category_id', $byEmployee->id)->value('employee_category_id'), 'no employee was re-pointed or blanked');
    }

    public function test_archiving_hides_a_category_from_new_registrations_but_changes_nothing_that_already_uses_it(): void
    {
        $category = $this->category('Old trade');
        $employee = $this->makeEmployee();
        $employee->update(['employee_category_id' => $category->id]);
        $other = EmployeeCategory::query()->where('id', '!=', $category->id)->firstOrFail();

        $this->as($this->manager)->postJson(self::URL."/{$category->id}/archive")->assertOk()->assertJsonPath('data.archived', true);
        $this->as($this->manager)->postJson(self::URL."/{$category->id}/archive")->assertUnprocessable()->assertJsonPath('error_code', 'CATEGORY_ARCHIVE_NO_CHANGE');
        $this->assertSame($category->id, $employee->fresh()->employee_category_id);
        $this->assertTrue(AuditLog::query()->where('entity_type', EmployeeCategory::class)->where('entity_id', $category->id)->where('description', 'like', '%archived%')->exists());

        // New registrations and new requests cannot pick it.
        $this->as($this->manager)->postJson('/api/v1/admin/hr/employees', ['full_name' => 'Test Person', 'phone' => '0612000111', 'location' => 'Hodan', 'gender' => 'female', 'employee_category_id' => $category->id, 'application_date' => '2026-10-21'])
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_category_id'], 'errors');
        $this->as($this->manager)->postJson('/api/v1/admin/hr/workforce-requests', ['client_company_id' => $this->makeCompany('Gamma')->id, 'employee_category_id' => $category->id, 'quantity_needed' => 1, 'requested_date' => '2026-10-21'])
            ->assertUnprocessable()->assertJsonValidationErrors(['employee_category_id'], 'errors');

        // Editing the employee who already has it keeps working; moving someone INTO it does not.
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['employee_category_id' => $category->id, 'notes' => 'still fine'])->assertOk();
        $someone = $this->makeEmployee();
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$someone->id}", ['employee_category_id' => $category->id])->assertUnprocessable()->assertJsonValidationErrors(['employee_category_id'], 'errors');
        $this->assertSame($other->id, $someone->fresh()->employee_category_id);

        // Restoring makes it selectable again.
        $this->as($this->manager)->postJson(self::URL."/{$category->id}/restore")->assertOk()->assertJsonPath('data.archived', false);
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$someone->id}", ['employee_category_id' => $category->id])->assertOk();
        $this->as($this->manager)->postJson(self::URL."/{$category->id}/restore")->assertUnprocessable();
    }

    public function test_the_office_staff_category_can_be_neither_archived_nor_deleted_nor_renamed(): void
    {
        $office = EmployeeCategory::officeStaff() ?? $this->category(EmployeeCategory::OFFICE_STAFF);

        $this->as($this->manager)->postJson(self::URL."/{$office->id}/archive")->assertUnprocessable()->assertJsonPath('error_code', 'CATEGORY_SYSTEM');
        $this->as($this->manager)->deleteJson(self::URL."/{$office->id}")->assertUnprocessable()->assertJsonPath('error_code', 'CATEGORY_SYSTEM');
        $this->as($this->manager)->putJson(self::URL."/{$office->id}", ['name' => 'Back Office'])->assertUnprocessable();
        $this->assertNull($office->fresh()->archived_at);
        $this->assertDatabaseHas('employee_categories', ['id' => $office->id, 'name' => $office->name]);
    }

    public function test_changing_categories_needs_hr_manage_and_no_wider_permission_is_introduced(): void
    {
        $category = $this->category('Spare');

        $this->as($this->viewer)->postJson(self::URL, ['name' => 'Nope'])->assertForbidden();
        $this->as($this->viewer)->postJson(self::URL."/{$category->id}/archive")->assertForbidden();
        $this->as($this->viewer)->postJson(self::URL."/{$category->id}/restore")->assertForbidden();
        $this->as($this->viewer)->deleteJson(self::URL."/{$category->id}")->assertForbidden();
        $this->assertDatabaseHas('employee_categories', ['id' => $category->id, 'archived_at' => null]);
    }
}
