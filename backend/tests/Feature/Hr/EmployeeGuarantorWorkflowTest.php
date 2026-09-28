<?php

namespace Tests\Feature\Hr;

use App\Actions\Hr\CreateOrUpdateEmployeeGuarantorAction;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Verifies the EXISTING guarantor architecture already supports "add a
 * guarantor later, from the Employee profile, without creating a duplicate
 * Employee" — the confirmed future-guarantor workflow for Excel-migrated
 * people who currently have guarantor_need=YES (or guarantor_need=NO) with
 * no actual guarantor record yet. No production code changed here.
 */
class EmployeeGuarantorWorkflowTest extends TestCase
{
    use RefreshDatabase;

    public function test_adding_a_guarantor_later_attaches_to_the_existing_employee_without_creating_a_duplicate(): void
    {
        $admin = Admin::factory()->create();
        $category = EmployeeCategory::query()->firstOrFail();

        $employee = Employee::query()->create([
            'employee_number' => 'EMP-TEST-0001',
            'full_name' => 'Test Migrated Person',
            'phone' => '615000099',
            'employee_category_id' => $category->id,
            'status' => 'applicant',
            'application_date' => now()->toDateString(),
        ]);

        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('employee_guarantors', 0);

        $action = app(CreateOrUpdateEmployeeGuarantorAction::class);

        $guarantor = $action->handle($employee, [
            'guarantor_name' => 'Real Guarantor Name',
            'guarantor_phone' => '615111222',
            'relationship' => 'Brother',
        ], $admin);

        $this->assertSame($employee->id, $guarantor->employee_id);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('employee_guarantors', 1);

        // Adding/editing the guarantor again later (the "future guarantor workflow")
        // must update the SAME record, never create a second Employee or a second
        // guarantor row for the same person.
        $updated = $action->handle($employee, [
            'guarantor_name' => 'Corrected Guarantor Name',
            'guarantor_phone' => '615111222',
            'relationship' => 'Brother',
        ], $admin);

        $this->assertSame($guarantor->id, $updated->id);
        $this->assertSame($employee->id, $updated->employee_id);
        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('employee_guarantors', 1);
        $this->assertSame('Corrected Guarantor Name', $updated->guarantor_name);
    }
}
