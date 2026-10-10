<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

class EmployeeDuplicateProtectionTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private function register(string $token, array $overrides = [])
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/employees', array_merge([
            'full_name' => fake()->unique()->name(),
            'phone' => '615123456',
            'location' => 'Hodan, Mogadishu',
            'gender' => 'female',
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'application_date' => now()->toDateString(),
        ], $overrides));
    }

    public function test_a_phone_that_already_exists_is_refused_in_any_common_format(): void
    {
        [, $token] = $this->superAdminToken();
        $existing = $this->makeEmployee('waiting', 'permanent', '615123456');

        foreach (['615123456', '0615123456', '+252615123456', '252 615 123 456'] as $format) {
            $this->register($token, ['phone' => $format])
                ->assertStatus(422)
                ->assertJsonPath('error_code', 'DUPLICATE_EMPLOYEE_PHONE')
                ->assertJsonPath('errors.matches.0.id', $existing->id)
                ->assertJsonPath('errors.matches.0.employee_number', $existing->employee_number);
        }

        $this->assertDatabaseCount('employees', 1);
    }

    public function test_a_matching_alternate_phone_and_an_archived_record_are_also_found(): void
    {
        [, $token] = $this->superAdminToken();
        $archived = $this->makeEmployee('inactive', 'permanent', '611000111');
        $archived->update(['alternate_phone' => '615123456']);
        $archived->delete();

        $this->register($token)
            ->assertStatus(422)
            ->assertJsonPath('errors.matches.0.id', $archived->id)
            ->assertJsonPath('errors.matches.0.archived', true);
    }

    public function test_a_confirmed_override_needs_a_reason_and_is_audited(): void
    {
        [, $token] = $this->superAdminToken();
        $existing = $this->makeEmployee('active', 'permanent', '615123456');

        $this->register($token, ['confirm_duplicate' => true])
            ->assertStatus(422)->assertJsonValidationErrors('duplicate_reason');

        $this->register($token, ['confirm_duplicate' => true, 'duplicate_reason' => 'Different person sharing a family phone.'])
            ->assertCreated();

        $this->assertDatabaseCount('employees', 2);
        $log = \DB::table('audit_logs')->where('description', 'like', 'Employee % registered%')->orderByDesc('id')->first();
        $metadata = json_decode($log->metadata, true);
        $this->assertTrue($metadata['duplicate_override']);
        $this->assertSame([$existing->id], $metadata['matched_employee_ids']);
        $this->assertSame('Different person sharing a family phone.', $metadata['reason']);
    }

    public function test_an_identical_name_alone_does_not_block_but_is_recorded(): void
    {
        [, $token] = $this->superAdminToken();
        $existing = $this->makeEmployee('active', 'permanent', '611000222');

        $this->register($token, ['full_name' => $existing->full_name, 'phone' => '618999888'])->assertCreated();

        $this->assertDatabaseCount('employees', 2);
        $log = \DB::table('audit_logs')->where('description', 'like', 'Employee % registered%')->orderByDesc('id')->first();
        $this->assertSame([$existing->id], json_decode($log->metadata, true)['possible_same_name_employee_ids']);
    }

    public function test_short_numbers_are_never_treated_as_a_match(): void
    {
        [, $token] = $this->superAdminToken();
        $this->makeEmployee('active', 'permanent', '12345');

        $this->register($token, ['phone' => '12345'])->assertCreated();
    }

    public function test_the_duplicate_check_endpoint_previews_matches_for_hr_view(): void
    {
        $existing = $this->makeEmployee('waiting', 'permanent', '615123456');
        $viewer = $this->tokenWithOnly(['hr.view']);

        $this->withToken($viewer)
            ->getJson('/api/v1/admin/hr/employees/duplicate-check?phone=0615123456&full_name='.urlencode($existing->full_name))
            ->assertOk()
            ->assertJsonPath('data.0.id', $existing->id)
            ->assertJsonPath('data.0.matched_on', ['phone', 'name']);
    }

    public function test_editing_a_phone_to_another_employees_number_needs_confirmation(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeEmployee('active', 'permanent', '615123456');
        $b = $this->makeEmployee('active', 'permanent', '618777666');

        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$b->id}", ['phone' => '0615123456'])
            ->assertStatus(422)->assertJsonPath('error_code', 'DUPLICATE_EMPLOYEE_PHONE')->assertJsonPath('errors.matches.0.id', $a->id);
        $this->assertSame('618777666', $b->refresh()->phone);

        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$b->id}", ['phone' => '0615123456', 'confirm_duplicate' => true, 'duplicate_reason' => 'Shared household phone, verified.'])
            ->assertOk();
        $this->assertSame('0615123456', $b->refresh()->phone);
    }

    public function test_re_saving_an_employees_own_phone_is_not_a_duplicate(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeEmployee('active', 'permanent', '615123456');

        $this->withToken($token)->putJson("/api/v1/admin/hr/employees/{$a->id}", ['phone' => '615123456', 'location' => 'New place'])->assertOk();
    }

    public function test_employment_type_defaults_to_permanent_and_accepts_temporary_only_from_the_allowed_values(): void
    {
        [, $token] = $this->superAdminToken();

        $this->register($token, ['phone' => '611111111'])->assertCreated()->assertJsonPath('data.employment_type', 'permanent');
        $this->register($token, ['phone' => '622222222', 'employment_type' => 'temporary'])->assertCreated()->assertJsonPath('data.employment_type', 'temporary');
        $this->register($token, ['phone' => '633333333', 'employment_type' => 'seasonal'])->assertStatus(422)->assertJsonValidationErrors('employment_type');

        $this->assertSame(1, Employee::query()->where('employment_type', 'temporary')->count());
    }
}
