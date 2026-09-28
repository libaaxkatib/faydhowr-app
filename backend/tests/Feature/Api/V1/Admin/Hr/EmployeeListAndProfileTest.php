<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Phase 1 (Employee Management + Employee Profile): list/search/filter/
 * pagination and the profile-facing fields added for the Excel HR migration
 * (category_specialization, guarantor_needed, profile_complete) plus the
 * nullable-phone/application_date update path. General GET /employees and
 * GET /employees/{id} authorization boundaries (401/403) are already covered
 * by HrAuthorizationBoundaryTest — not duplicated here. PUT /employees/{id}
 * authorization was NOT previously covered by that file — added here.
 */
class EmployeeListAndProfileTest extends TestCase
{
    use RefreshDatabase;

    private function actingToken(): string
    {
        return Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
    }

    private function tokenWithOnly(AdminPermission $permission): string
    {
        $admin = Admin::factory()->create(['role' => AdminRole::Manager]);

        DB::table('admin_permissions')->insert([
            'admin_id' => $admin->id,
            'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $admin->createToken('t')->plainTextToken;
    }

    private function category(string $name = 'General Cleaning'): EmployeeCategory
    {
        return EmployeeCategory::query()->firstOrCreate(['name' => $name]);
    }

    private function makeEmployee(array $overrides = []): Employee
    {
        return Employee::query()->create(array_merge([
            'employee_number' => 'EMP-'.fake()->unique()->numerify('######'),
            'full_name' => fake()->name(),
            'phone' => fake()->unique()->numerify('6########'),
            'employee_category_id' => $this->category()->id,
            'status' => 'applicant',
        ], $overrides));
    }

    // --- 3. Employee update authorization (gap not covered by HrAuthorizationBoundaryTest) ---

    public function test_update_employee_rejects_a_request_with_no_token_at_all(): void
    {
        $employee = $this->makeEmployee();

        $this->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['full_name' => 'New Name'])
            ->assertStatus(401);
    }

    public function test_update_employee_rejects_an_admin_with_only_hr_view(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->tokenWithOnly(AdminPermission::HrView);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['full_name' => 'New Name'])
            ->assertStatus(403);
    }

    public function test_update_employee_succeeds_for_an_admin_with_hr_manage(): void
    {
        $employee = $this->makeEmployee();
        $token = $this->tokenWithOnly(AdminPermission::HrManage);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['full_name' => 'New Name'])
            ->assertOk()
            ->assertJsonPath('data.full_name', 'New Name');
    }

    // --- 4. Search ---

    public function test_search_matches_full_name_phone_and_employee_number(): void
    {
        $token = $this->actingToken();
        $target = $this->makeEmployee(['full_name' => 'Amina Hassan Warsame', 'phone' => '615999001']);
        $this->makeEmployee(['full_name' => 'Someone Else']);

        foreach (['Amina Hassan', '615999001', $target->employee_number] as $term) {
            $this->withToken($token)->getJson('/api/v1/admin/hr/employees?search='.urlencode($term))
                ->assertOk()
                ->assertJsonPath('data.0.id', $target->id)
                ->assertJsonCount(1, 'data');
        }
    }

    // --- 5-13. Filters ---

    public function test_status_filter(): void
    {
        $token = $this->actingToken();
        $active = $this->makeEmployee(['status' => 'active']);
        $this->makeEmployee(['status' => 'applicant']);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $active->id);
    }

    public function test_category_filter(): void
    {
        $token = $this->actingToken();
        $cooking = $this->category('Cooking Test');
        $match = $this->makeEmployee(['employee_category_id' => $cooking->id]);
        $this->makeEmployee();

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?employee_category_id='.$cooking->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_department_filter(): void
    {
        $token = $this->actingToken();
        $department = Department::query()->create(['name' => 'Operations Test']);
        $match = $this->makeEmployee(['department_id' => $department->id]);
        $this->makeEmployee();

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?department_id='.$department->id)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_location_filter(): void
    {
        $token = $this->actingToken();
        $match = $this->makeEmployee(['location' => 'Hodan, Mogadishu']);
        $this->makeEmployee(['location' => 'Karan']);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?location=hodan')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_profile_complete_filter_supports_both_true_and_false(): void
    {
        $token = $this->actingToken();
        $complete = $this->makeEmployee(['profile_complete' => true]);
        $incomplete = $this->makeEmployee(['profile_complete' => false]);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?profile_complete=true')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $complete->id);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?profile_complete=false')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $incomplete->id);
    }

    public function test_guarantor_needed_filter_supports_both_true_and_false(): void
    {
        $token = $this->actingToken();
        $needed = $this->makeEmployee(['guarantor_needed' => true]);
        $notNeeded = $this->makeEmployee(['guarantor_needed' => false]);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?guarantor_needed=true')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $needed->id);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?guarantor_needed=false')
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $notNeeded->id);
    }

    public function test_supervisor_filter(): void
    {
        $token = $this->actingToken();
        $supervisor = $this->makeEmployee(['is_supervisor' => true]);
        $this->makeEmployee(['is_supervisor' => false]);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees?is_supervisor=true')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $supervisor->id);
    }

    public function test_application_date_range_filter(): void
    {
        $token = $this->actingToken();
        $inRange = $this->makeEmployee(['application_date' => '2025-06-15']);
        $this->makeEmployee(['application_date' => '2024-01-01']);
        $this->makeEmployee(['application_date' => null]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/hr/employees?application_date_from=2025-01-01&application_date_to=2025-12-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRange->id);
    }

    public function test_joining_date_range_filter(): void
    {
        $token = $this->actingToken();
        $inRange = $this->makeEmployee(['joining_date' => '2025-06-15']);
        $this->makeEmployee(['joining_date' => '2024-01-01']);
        $this->makeEmployee(['joining_date' => null]);

        $this->withToken($token)
            ->getJson('/api/v1/admin/hr/employees?joining_date_from=2025-01-01&joining_date_to=2025-12-31')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $inRange->id);
    }

    // --- 14. Filter combinations ---

    public function test_filters_combine_with_and_semantics(): void
    {
        $token = $this->actingToken();
        $cooking = $this->category('Cooking Combo Test');
        $match = $this->makeEmployee(['employee_category_id' => $cooking->id, 'status' => 'active']);
        $this->makeEmployee(['employee_category_id' => $cooking->id, 'status' => 'applicant']);
        $this->makeEmployee(['status' => 'active']);

        $this->withToken($token)
            ->getJson('/api/v1/admin/hr/employees?employee_category_id='.$cooking->id.'&status=active')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $match->id);
    }

    // --- 15. Pagination ---

    public function test_pagination_respects_per_page_and_reports_total(): void
    {
        $token = $this->actingToken();

        for ($i = 0; $i < 5; $i++) {
            $this->makeEmployee();
        }

        $response = $this->withToken($token)->getJson('/api/v1/admin/hr/employees?per_page=2&page=1')->assertOk();

        $response->assertJsonCount(2, 'data');
        self::assertSame(2, $response->json('meta.per_page'));
        self::assertSame(1, $response->json('meta.current_page'));
        self::assertSame(5, $response->json('meta.total'));
        self::assertSame(3, $response->json('meta.last_page'));
    }

    // --- 16-17. Nullable phone / application_date update ---

    public function test_updating_an_employee_to_a_null_phone_is_accepted_not_fabricated(): void
    {
        $token = $this->actingToken();
        $employee = $this->makeEmployee(['phone' => '615000123']);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['phone' => null])
            ->assertOk()
            ->assertJsonPath('data.phone', null);

        self::assertNull($employee->fresh()->phone);
    }

    public function test_updating_an_employee_to_a_null_application_date_is_accepted(): void
    {
        $token = $this->actingToken();
        $employee = $this->makeEmployee(['application_date' => '2025-01-01']);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['application_date' => null])
            ->assertOk()
            ->assertJsonPath('data.application_date', null);

        self::assertNull($employee->fresh()->application_date);
    }

    public function test_saving_an_unrelated_field_on_an_employee_with_no_phone_no_location_no_gender_does_not_require_fabricating_them(): void
    {
        $token = $this->actingToken();
        $employee = $this->makeEmployee(['phone' => null, 'location' => null, 'gender' => null]);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['notes' => 'Reviewed by HR Manager.'])
            ->assertOk()
            ->assertJsonPath('data.notes', 'Reviewed by HR Manager.')
            ->assertJsonPath('data.phone', null)
            ->assertJsonPath('data.location', null)
            ->assertJsonPath('data.gender', null);
    }

    // --- 18-20. New field exposure ---

    public function test_show_exposes_category_specialization_guarantor_needed_and_profile_complete(): void
    {
        $token = $this->actingToken();
        $employee = $this->makeEmployee([
            'category_specialization' => 'Full Time - Jiif',
            'guarantor_needed' => true,
            'profile_complete' => false,
        ]);

        $this->withToken($token)->getJson("/api/v1/admin/hr/employees/{$employee->id}")
            ->assertOk()
            ->assertJsonPath('data.category_specialization', 'Full Time - Jiif')
            ->assertJsonPath('data.guarantor_needed', true)
            ->assertJsonPath('data.profile_complete', false);
    }

    public function test_list_exposes_category_specialization_guarantor_needed_and_profile_complete(): void
    {
        $token = $this->actingToken();
        $this->makeEmployee([
            'category_specialization' => 'Cook',
            'guarantor_needed' => true,
            'profile_complete' => false,
        ]);

        $this->withToken($token)->getJson('/api/v1/admin/hr/employees')
            ->assertOk()
            ->assertJsonPath('data.0.category_specialization', 'Cook')
            ->assertJsonPath('data.0.guarantor_needed', true)
            ->assertJsonPath('data.0.profile_complete', false);
    }

    // --- 21-22. New field update ---

    public function test_category_specialization_is_editable(): void
    {
        $token = $this->actingToken();
        $employee = $this->makeEmployee(['category_specialization' => 'Cook']);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['category_specialization' => 'Cunto & Nadaafad'])
            ->assertOk()
            ->assertJsonPath('data.category_specialization', 'Cunto & Nadaafad');
    }

    public function test_application_date_is_editable(): void
    {
        $token = $this->actingToken();
        $employee = $this->makeEmployee(['application_date' => null]);

        $this->withToken($token)
            ->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['application_date' => '2026-01-15'])
            ->assertOk()
            ->assertJsonPath('data.application_date', '2026-01-15');
    }
}
