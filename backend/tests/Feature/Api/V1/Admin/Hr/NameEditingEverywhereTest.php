<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Enums\FacilityItemKey;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\FacilityComplaint;
use App\Models\FacilityInspection;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Position;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Every name can be corrected by someone allowed to, and a correction changes ONLY the name: the id, assignments, payroll lines, inspections,
 * complaints, history and audit stay attached to the same record, the new name shows up everywhere it is displayed, duplicates are refused
 * clearly, and closed payroll months keep the names they were approved with.
 */
class NameEditingEverywhereTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $manager;

    private string $viewer;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
        $this->manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.reports.view', 'hr.inspections.view', 'hr.inspections.manage']);
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view']);
        [, $this->super] = $this->superAdminToken();
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function items(): array
    {
        return array_map(fn (FacilityItemKey $k): array => ['item_key' => $k->value, 'applicable' => true, 'result' => 'satisfactory'], FacilityItemKey::cases());
    }

    /** One company with one employee, an open (draft) September run and a CLOSED (approved) August run, an inspection and a complaint. */
    private function world(string $company = 'ABAALHOSPITAL'): array
    {
        $company = $this->makeCompany($company);
        $location = $this->makeClientLocation($company, 'Ward A');
        $employee = $this->makeEmployee('active', 'permanent', '0615111222');
        $employee->forceFill(['payment_account_number' => '0699000111'])->save();
        $assignment = $this->makeAssignment($employee, $company, $location, 300, '2026-01-01');

        foreach (['2026-08', '2026-09'] as $period) {
            $this->as($this->super)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => $period])->assertCreated();
        }
        PayrollRun::query()->where('period', '2026-08')->update(['status' => PayrollRun::STATUS_APPROVED]);

        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', [
            'client_company_id' => $company->id, 'inspector_name' => 'Inspector', 'inspected_on' => '2026-10-10', 'items' => $this->items(),
            'staff_complaints_reported' => false, 'customer_complaints_reported' => false,
        ])->assertCreated();
        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-complaints', [
            'client_company_id' => $company->id, 'type' => 'customer', 'details' => 'The floor was wet', 'reporter_name' => 'Mr Ahmed', 'reported_on' => '2026-10-12',
        ])->assertCreated();

        return [$company, $location, $employee, $assignment];
    }

    private function lineNames(string $period): array
    {
        return PayrollItem::query()->where('period', $period)->get(['employee_name', 'company_name', 'workplace_name'])->map(fn ($i): array => [$i->employee_name, $i->company_name, $i->workplace_name])->all();
    }

    private function paymentListRows(): array
    {
        $response = $this->as($this->super)->get('/api/v1/admin/hr/reports/monthly-payment-list?period=2026-09')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        @unlink($path);
        $rows = $book->getSheetByName('Employees')->toArray(null, true, false, false);

        return collect(array_slice($rows, 4))->filter(fn (array $r): bool => $r[0] !== null && $r[1] !== null)->map(fn (array $r): array => array_combine($rows[3], $r))->values()->all();
    }

    // ------------------------------------------------------------------ companies

    public function test_a_company_rename_changes_the_name_everywhere_and_nothing_else(): void
    {
        [$company, , $employee, $assignment] = $this->world('5GHOTEL');
        $inspection = FacilityInspection::query()->firstOrFail();
        $complaint = FacilityComplaint::query()->firstOrFail();

        $this->as($this->manager)->putJson("/api/v1/admin/hr/client-companies/{$company->id}", ['name' => '5G HOTEL'])->assertOk()->assertJsonPath('data.id', $company->id)->assertJsonPath('data.name', '5G HOTEL');

        // Same record, same relations.
        $this->assertSame(1, ClientCompany::query()->count());
        $this->assertSame($company->id, $assignment->fresh()->client_company_id);
        $this->assertSame($company->id, $inspection->fresh()->client_company_id);
        $this->assertSame($company->id, $complaint->fresh()->client_company_id);
        $this->assertDatabaseHas('client_company_aliases', ['client_company_id' => $company->id, 'alias' => '5GHOTEL']);

        // The new name is what every screen reads.
        $this->assertContains('5G HOTEL', collect($this->as($this->manager)->getJson('/api/v1/admin/hr/client-companies')->json('data'))->pluck('name')->all());
        $this->as($this->manager)->getJson("/api/v1/admin/hr/client-companies/{$company->id}/employees?period=2026-09")->assertOk()->assertJsonPath('data.company.name', '5G HOTEL');
        $this->assertSame('5G HOTEL', $this->as($this->manager)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->json('data.active_work_assignments.0.client_company_name'));
        $this->assertSame('5G HOTEL', $this->as($this->manager)->getJson('/api/v1/admin/hr/facility-inspections')->json('data.0.company'));
        $this->assertSame('5G HOTEL', $this->as($this->manager)->getJson('/api/v1/admin/hr/facility-complaints')->json('data.0.company'));
        $this->assertSame('5G HOTEL', collect($this->as($this->manager)->getJson('/api/v1/admin/hr/facility-inspections/status?search=5g+hotel')->json('data'))->first()['company'], 'the status board finds it by the new name');
        $this->assertSame([], $this->as($this->manager)->getJson('/api/v1/admin/hr/facility-inspections/status?search=5ghotel')->json('data'), 'the old spelling is an alias, not a second company');
        $this->assertSame('5G HOTEL', $this->paymentListRows()[0]['Company / Guri']);

        // Open payroll follows the correction; the closed August month keeps the name it was approved with.
        $this->assertSame([[$employee->full_name, '5G HOTEL', 'Ward A']], $this->lineNames('2026-09'));
        $this->assertSame([[$employee->full_name, '5GHOTEL', 'Ward A']], $this->lineNames('2026-08'));

        // Audited with before and after.
        $log = AuditLog::query()->where('entity_type', ClientCompany::class)->where('entity_id', $company->id)->latest('id')->firstOrFail();
        $this->assertSame(['renamed_from' => '5GHOTEL', 'renamed_to' => '5G HOTEL', 'old_name_kept_as_alias' => true], $log->metadata);
    }

    public function test_company_name_edits_are_validated_and_permissioned(): void
    {
        $a = $this->makeCompany('Alpha Hospital');
        $b = $this->makeCompany('Beta School');

        foreach (['alpha hospital', 'ALPHA  HOSPITAL', 'AlphaHospital'] as $clash) {
            $this->as($this->manager)->putJson("/api/v1/admin/hr/client-companies/{$b->id}", ['name' => $clash])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        }
        $this->as($this->manager)->putJson("/api/v1/admin/hr/client-companies/{$b->id}", ['name' => '   '])->assertUnprocessable();
        $this->as($this->viewer)->putJson("/api/v1/admin/hr/client-companies/{$b->id}", ['name' => 'Gamma'])->assertForbidden();
        $this->assertSame(['Alpha Hospital', 'Beta School'], ClientCompany::query()->orderBy('id')->pluck('name')->all());

        // Saving the same name again, or changing only its capital letters, is fine and adds no duplicate.
        $this->as($this->manager)->putJson("/api/v1/admin/hr/client-companies/{$a->id}", ['name' => 'ALPHA HOSPITAL'])->assertOk();
        $this->assertSame(2, ClientCompany::query()->count());
    }

    // ------------------------------------------------------------------ workplaces

    public function test_a_workplace_rename_keeps_its_assignments_and_follows_open_payroll_but_not_closed_months(): void
    {
        [$company, $location, $employee, $assignment] = $this->world();

        $this->as($this->manager)->putJson("/api/v1/admin/hr/work-locations/{$location->id}", ['name' => '  Ward   B '])->assertOk()->assertJsonPath('data.id', $location->id)->assertJsonPath('data.name', 'Ward B');

        $this->assertSame($location->id, $assignment->fresh()->work_location_id);
        $this->assertSame('Ward B', $this->as($this->manager)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->json('data.active_work_assignments.0.work_location_name'));
        $this->assertSame([[$employee->full_name, 'ABAALHOSPITAL', 'Ward B']], $this->lineNames('2026-09'));
        $this->assertSame([[$employee->full_name, 'ABAALHOSPITAL', 'Ward A']], $this->lineNames('2026-08'));
        $log = AuditLog::query()->where('entity_type', WorkLocation::class)->where('entity_id', $location->id)->latest('id')->firstOrFail();
        $this->assertSame(['renamed_from' => 'Ward A', 'renamed_to' => 'Ward B'], $log->metadata);
    }

    public function test_a_workplace_name_is_unique_inside_its_company_only(): void
    {
        $company = $this->makeCompany('Alpha Hospital');
        $other = $this->makeCompany('Beta School');
        $first = $this->makeClientLocation($company, 'Ward A');
        $second = $this->makeClientLocation($company, 'Ward B');
        $this->makeClientLocation($other, 'Ward A');

        // Another company may have the same workplace name; the same company may not (capital letters and spaces ignored).
        $this->as($this->manager)->putJson("/api/v1/admin/hr/work-locations/{$second->id}", ['name' => 'ward  a'])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        $this->as($this->manager)->postJson('/api/v1/admin/hr/work-locations', ['location_type' => 'client', 'client_company_id' => $company->id, 'name' => 'WARD A'])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        $this->as($this->manager)->postJson('/api/v1/admin/hr/work-locations', ['location_type' => 'client', 'client_company_id' => $other->id, 'name' => 'Ward C'])->assertCreated();
        $this->as($this->manager)->putJson("/api/v1/admin/hr/work-locations/{$first->id}", ['name' => 'Ward A', 'notes' => 'same name again'])->assertOk();
        $this->as($this->manager)->putJson("/api/v1/admin/hr/work-locations/{$first->id}", ['name' => '   '])->assertUnprocessable();
        $this->as($this->viewer)->putJson("/api/v1/admin/hr/work-locations/{$first->id}", ['name' => 'Ward Z'])->assertForbidden();
        $this->assertSame('Ward A', $first->fresh()->name);
    }

    // ------------------------------------------------------------------ employees

    public function test_an_employee_rename_changes_only_the_name(): void
    {
        [$company, , $employee, $assignment] = $this->world();
        $before = Employee::query()->findOrFail($employee->id)->only(['id', 'employee_number', 'phone', 'payment_account_number', 'status', 'employee_category_id', 'position_id']);
        $oldName = $employee->full_name;
        $assignmentBefore = $assignment->fresh()->only(['id', 'client_company_id', 'work_location_id', 'position_id', 'salary_amount', 'salary_currency', 'shift', 'start_date', 'end_date', 'status']);

        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$employee->id}", ['full_name' => '  Amina   Hassan  '])->assertOk()->assertJsonPath('data.full_name', 'Amina Hassan')->assertJsonPath('data.id', $employee->id);

        $this->assertSame($before, Employee::query()->findOrFail($employee->id)->only(['id', 'employee_number', 'phone', 'payment_account_number', 'status', 'employee_category_id', 'position_id']));
        $this->assertEquals($assignmentBefore, $assignment->fresh()->only(['id', 'client_company_id', 'work_location_id', 'position_id', 'salary_amount', 'salary_currency', 'shift', 'start_date', 'end_date', 'status']));

        // Search finds the new name, not the old one; the same record serves the list, the detail and the company screen.
        $found = $this->as($this->manager)->getJson('/api/v1/admin/hr/employees?search=Hassan')->json('data');
        $this->assertSame([$employee->id], collect($found)->pluck('id')->all());
        $this->assertSame([], $this->as($this->manager)->getJson('/api/v1/admin/hr/employees?search='.urlencode($oldName))->json('data'));
        $this->assertSame('Amina Hassan', $this->as($this->manager)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->json('data.full_name'));
        $row = collect($this->as($this->manager)->getJson("/api/v1/admin/hr/client-companies/{$company->id}/employees?period=2026-09")->json('data.employees'))->firstWhere('employee_id', $employee->id);
        $this->assertSame('Amina Hassan', $row['full_name']);

        // Payment list (reads the live name) and the OPEN run follow; the CLOSED month keeps what it was approved with.
        $this->assertSame('Amina Hassan', $this->paymentListRows()[0]['Employee name']);
        $this->assertSame('0699000111', $this->paymentListRows()[0]['Payment / account number'], 'the payment number keeps its leading zero and is untouched by the rename');
        $this->assertSame([['Amina Hassan', 'ABAALHOSPITAL', 'Ward A']], $this->lineNames('2026-09'));
        $this->assertSame([[$oldName, 'ABAALHOSPITAL', 'Ward A']], $this->lineNames('2026-08'));
        $this->assertSame($employee->id, PayrollItem::query()->where('period', '2026-09')->value('employee_id'));

        // Audited with before and after; the payment number is not in the trail.
        $log = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $employee->id)->latest('id')->firstOrFail();
        $this->assertSame(['full_name' => ['from' => $oldName, 'to' => 'Amina Hassan']], $log->metadata);
        $this->assertStringContainsString("renamed to 'Amina Hassan'", $log->description);
    }

    public function test_an_employee_name_edit_is_validated_permissioned_and_allows_a_shared_name_with_a_warning_available(): void
    {
        $one = $this->makeEmployee();
        $two = $this->makeEmployee();
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$one->id}", ['full_name' => '   '])->assertUnprocessable()->assertJsonValidationErrors(['full_name'], 'errors');
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$one->id}", ['full_name' => str_repeat('x', 151)])->assertUnprocessable();
        $this->as($this->viewer)->putJson("/api/v1/admin/hr/employees/{$one->id}", ['full_name' => 'Nobody'])->assertForbidden();
        $this->assertNotSame('Nobody', $one->fresh()->full_name);

        // Two different people may legitimately share a name: the edit is allowed, and the panel's name check tells the editor about the other person.
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employees/{$two->id}", ['full_name' => $one->full_name])->assertOk();
        $check = $this->as($this->manager)->getJson('/api/v1/admin/hr/employees/duplicate-check?full_name='.urlencode($one->full_name).'&ignore_employee_id='.$two->id)->assertOk();
        $this->assertSame([$one->id], collect($check->json('data.name_matches') ?? $check->json('data'))->pluck('id')->all());
    }

    // ------------------------------------------------------------------ positions, departments, categories

    public function test_a_position_rename_keeps_the_same_position_everywhere_and_refuses_duplicates(): void
    {
        [$company, , $employee, $assignment] = $this->world();
        $position = Position::query()->create(['name' => 'Cleaner']);
        $other = Position::query()->create(['name' => 'Supervisor']);
        $assignment->update(['position_id' => $position->id]);
        $employee->update(['position_id' => $position->id]);

        $this->as($this->manager)->putJson("/api/v1/admin/hr/positions/{$position->id}", ['name' => '  Senior   Cleaner '])->assertOk()->assertJsonPath('data.id', $position->id)->assertJsonPath('data.name', 'Senior Cleaner');
        $this->assertSame($position->id, $assignment->fresh()->position_id);
        $this->assertSame('Senior Cleaner', collect($this->as($this->manager)->getJson('/api/v1/admin/hr/positions')->json('data'))->firstWhere('id', $position->id)['name']);
        $row = collect($this->as($this->manager)->getJson("/api/v1/admin/hr/client-companies/{$company->id}/employees?period=2026-09")->json('data.employees'))->firstWhere('employee_id', $employee->id);
        $this->assertSame('Senior Cleaner', $row['position_name'] ?? $row['position']['name'] ?? null);

        $this->as($this->manager)->putJson("/api/v1/admin/hr/positions/{$other->id}", ['name' => 'senior  cleaner'])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        $this->as($this->viewer)->putJson("/api/v1/admin/hr/positions/{$other->id}", ['name' => 'Boss'])->assertForbidden();
        $this->assertSame('Supervisor', $other->fresh()->name);
    }

    public function test_a_department_rename_is_tidied_and_duplicates_ignore_case_and_spaces(): void
    {
        $a = $this->as($this->manager)->postJson('/api/v1/admin/hr/departments', ['name' => '  Night   Shift '])->assertCreated()->assertJsonPath('data.name', 'Night Shift')->json('data.id');
        $b = $this->as($this->manager)->postJson('/api/v1/admin/hr/departments', ['name' => 'Day Shift'])->assertCreated()->json('data.id');

        $this->as($this->manager)->postJson('/api/v1/admin/hr/departments', ['name' => 'night shift'])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        $this->as($this->manager)->putJson("/api/v1/admin/hr/departments/{$b}", ['name' => 'NIGHT  SHIFT'])->assertUnprocessable()->assertJsonValidationErrors(['name'], 'errors');
        $this->as($this->manager)->putJson("/api/v1/admin/hr/departments/{$b}", ['name' => 'Morning Shift'])->assertOk()->assertJsonPath('data.id', $b);
        $this->as($this->manager)->putJson("/api/v1/admin/hr/departments/{$a}", ['name' => 'NIGHT SHIFT'])->assertOk()->assertJsonPath('data.id', $a);
        $this->as($this->viewer)->putJson("/api/v1/admin/hr/departments/{$a}", ['name' => 'Nope'])->assertForbidden();
    }

    public function test_a_category_rename_keeps_every_employee_on_the_same_category(): void
    {
        $employee = $this->makeEmployee();
        $category = EmployeeCategory::query()->findOrFail($employee->employee_category_id);
        $this->as($this->manager)->putJson("/api/v1/admin/hr/employee-categories/{$category->id}", ['name' => 'Renamed Trade'])->assertOk()->assertJsonPath('data.id', $category->id);

        $this->assertSame($category->id, $employee->fresh()->employee_category_id);
        $this->assertSame('Renamed Trade', $this->as($this->manager)->getJson("/api/v1/admin/hr/employees/{$employee->id}")->json('data.employee_category_name'));
        $this->assertSame(1, collect($this->as($this->manager)->getJson("/api/v1/admin/hr/employees?employee_category_id={$category->id}")->json('data'))->count());
    }
}
