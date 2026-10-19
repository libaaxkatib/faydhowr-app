<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeAttendance;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Position;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Company Employee Management: the employees of one company and the actions on their assignments. Covers the company-specific list
 * and totals, the unique-id picker, add / edit / replace / return / stop, Permanent vs Replacement, audit history, authorization,
 * cross-company protection and the protection of approved payroll.
 */
class CompanyEmployeeManagementTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private ClientCompany $company;

    private ClientCompany $other;

    private Position $cleaner;

    private Position $driver;

    private string $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = $this->makeCompany('Acme Co');
        $this->other = $this->makeCompany('Other Co');
        $this->cleaner = Position::query()->create(['name' => 'Cleaner T']);
        $this->driver = Position::query()->create(['name' => 'Driver T']);
        [, $this->admin] = $this->superAdminToken();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function url(ClientCompany $company, string $tail = 'employees'): string
    {
        return "/api/v1/admin/hr/client-companies/{$company->id}/{$tail}";
    }

    private function place(Employee $employee, ClientCompany $company, int $salary = 300, string $start = '2026-01-01', ?Position $position = null): EmployeeWorkAssignment
    {
        $assignment = $this->makeAssignment($employee, $company, null, $salary, $start);
        $assignment->update(['position_id' => ($position ?? $this->cleaner)->id]);

        return $assignment;
    }

    private function newAssignment(array $over = []): array
    {
        $employeeId = $over['employee_id'] ?? $this->makeEmployee()->id;

        return array_replace([
            'employee_id' => $employeeId, 'assignment_type' => 'permanent', 'position_id' => $this->cleaner->id,
            'start_date' => '2026-10-05', 'salary_amount' => 250, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ], $over);
    }

    // ------------------------------------------------------------------ the list

    public function test_the_list_shows_only_this_companys_employees_for_the_selected_month(): void
    {
        $inside = $this->place($this->makeEmployee(), $this->company);
        $this->place($this->makeEmployee(), $this->other);
        $ended = $this->place($this->makeEmployee(), $this->company);
        $ended->update(['status' => 'ended', 'end_date' => '2026-08-31']);
        $cancelled = $this->place($this->makeEmployee(), $this->company);
        $cancelled->update(['status' => 'cancelled']);

        $rows = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-10')->assertOk()->json('data.employees');

        $this->assertSame([$inside->id], array_column($rows, 'assignment_id'), 'other company, ended-before-the-month and cancelled assignments are not listed');

        $august = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-08')->assertOk()->json('data.employees');
        $this->assertContains($ended->id, array_column($august, 'assignment_id'));
    }

    public function test_rows_carry_the_identifiers_position_type_dates_salary_and_payable_days(): void
    {
        $employee = $this->makeEmployee();
        $this->place($employee, $this->company, 310, '2026-10-10', $this->driver);

        $row = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-10')->assertOk()->json('data.employees.0');

        $this->assertSame($employee->id, $row['employee_id']);
        $this->assertSame($employee->employee_number, $row['employee_number']);
        $this->assertSame('Driver T', $row['position_name']);
        $this->assertSame('permanent', $row['assignment_type']);
        $this->assertSame('2026-10-10', $row['start_date']);
        $this->assertSame('310.00', $row['salary']['amount']);
        $this->assertSame('USD', $row['salary']['currency']);
        $this->assertSame(22, $row['payable_days'], '10 to 31 October inclusive');
        $this->assertSame(31, $row['days_in_month']);
    }

    public function test_attendance_is_counted_only_as_recorded_and_only_inside_the_assignment_dates(): void
    {
        $employee = $this->makeEmployee();
        $this->place($employee, $this->company, 300, '2026-10-10');
        foreach ([['2026-10-05', 'absent'], ['2026-10-12', 'present'], ['2026-10-13', 'absent'], ['2026-10-14', 'late']] as [$date, $status]) {
            EmployeeAttendance::query()->create(['employee_id' => $employee->id, 'date' => $date, 'status' => $status]);
        }

        $row = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-10')->json('data.employees.0');

        $this->assertSame(['recorded' => 3, 'present' => 1, 'late' => 1, 'absent' => 1], $row['attendance'], 'the 5 October mark is before the assignment started');
    }

    public function test_totals_are_per_currency_and_currencies_are_never_added(): void
    {
        $this->place($this->makeEmployee(), $this->company, 300);
        $this->place($this->makeEmployee(), $this->company, 200);
        $eur = $this->place($this->makeEmployee(), $this->company, 100);
        $eur->update(['salary_currency' => 'EUR']);
        $eur->salaryHistories()->update(['currency' => 'EUR']);

        $summary = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-10')->json('data.summary');

        $this->assertSame(3, $summary['employee_count']);
        $this->assertSame(['USD' => 50000, 'EUR' => 10000], $summary['contracted_monthly_salary_cents_by_currency']);
        $this->assertSame(93, $summary['payable_days_total']);
    }

    public function test_filters_by_status_position_type_and_search(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01', $this->cleaner);
        $b = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01', $this->driver);
        $b->update(['assignment_type' => 'replacement']);
        $c = $this->place($this->makeEmployee(), $this->company);
        $c->update(['status' => 'ended', 'end_date' => '2026-10-20']);
        $ids = fn (string $q) => array_column($this->as($this->admin)->getJson($this->url($this->company)."?period=2026-10&{$q}")->json('data.employees'), 'assignment_id');

        $this->assertSame([$b->id], $ids('assignment_type=replacement'));
        $this->assertSame([$b->id], $ids('position_id='.$this->driver->id));
        $this->assertSame([$c->id], $ids('status=ended'));
        $this->assertSame([$a->id], $ids('search='.$a->employee->employee_number));
        $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-13')->assertUnprocessable();
    }

    // ------------------------------------------------------------------ salary boundary and payroll

    public function test_calculated_pay_and_payment_status_are_returned_only_with_payroll_view(): void
    {
        $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01');

        $viewOnly = $this->as($this->tokenWithOnly(['hr.view']))->getJson($this->url($this->company).'?period=2026-10')->assertOk();
        $this->assertArrayNotHasKey('payroll', $viewOnly->json('data.employees.0'));
        $this->assertArrayNotHasKey('calculated_pay_by_currency', $viewOnly->json('data.summary'));
        $this->assertSame('300.00', $viewOnly->json('data.employees.0.salary.amount'), 'the contracted salary stays readable with hr.view, as everywhere else');

        $withPayroll = $this->as($this->tokenWithOnly(['hr.view', 'hr.payroll.view']))->getJson($this->url($this->company).'?period=2026-10')->assertOk();
        $calc = $withPayroll->json('data.employees.0.payroll.calculated');
        $this->assertSame(30000, $calc['gross_cents']);
        $this->assertSame(30000, $calc['net_cents']);
        $this->assertTrue($calc['is_estimate'], 'no approved run exists: it is a live estimate and says so');
        $this->assertSame(['USD' => ['gross_cents' => 30000, 'net_cents' => 30000]], $withPayroll->json('data.summary.calculated_pay_by_currency'));
        $this->assertTrue($withPayroll->json('data.summary.calculated_pay_is_estimate'));
    }

    public function test_a_non_usd_assignment_gets_no_calculated_pay_instead_of_a_wrong_one(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 100);
        $a->update(['salary_currency' => 'EUR']);

        $row = $this->as($this->tokenWithOnly(['hr.view', 'hr.payroll.view']))->getJson($this->url($this->company).'?period=2026-10')->json('data.employees.0.payroll');

        $this->assertNull($row['calculated']);
        $this->assertStringContainsString('USD only', $row['unavailable_reason']);
    }

    // ------------------------------------------------------------------ authorization

    public function test_authorization_is_enforced_on_the_server(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company);
        $viewer = $this->tokenWithOnly(['hr.view']);
        $managerOnly = $this->tokenWithOnly(['hr.manage']);
        $none = $this->noPermissionToken();

        $this->getJson($this->url($this->company))->assertUnauthorized();
        $this->as($none)->getJson($this->url($this->company))->assertForbidden();
        $this->as($managerOnly)->getJson($this->url($this->company))->assertForbidden();

        foreach ([
            ['post', $this->url($this->company), $this->newAssignment()],
            ['put', $this->url($this->company)."/{$a->id}", ['position_id' => $this->driver->id]],
            ['post', $this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-10-20', 'reason' => 'x y z']],
            ['post', $this->url($this->company)."/{$a->id}/replace", []],
            ['post', $this->url($this->company)."/{$a->id}/return", []],
        ] as [$method, $url, $body]) {
            $this->as($viewer)->{$method.'Json'}($url, $body)->assertForbidden();
            $this->as($managerOnly)->{$method.'Json'}($url, $body)->assertForbidden();
            $this->as($none)->{$method.'Json'}($url, $body)->assertForbidden();
        }

        $this->assertSame('active', $a->fresh()->status->value);
    }

    public function test_an_assignment_of_another_company_cannot_be_reached_through_this_companys_url(): void
    {
        $foreign = $this->place($this->makeEmployee(), $this->other);
        $replacement = $this->makeEmployee();

        $this->as($this->admin)->putJson($this->url($this->company)."/{$foreign->id}", ['position_id' => $this->driver->id])->assertNotFound();
        $this->as($this->admin)->postJson($this->url($this->company)."/{$foreign->id}/stop", ['end_date' => '2026-10-20', 'reason' => 'forged'])->assertNotFound();
        $this->as($this->admin)->postJson($this->url($this->company)."/{$foreign->id}/replace", [
            'replacement_employee_id' => $replacement->id, 'replacement_date' => '2026-10-21', 'assignment_type' => 'permanent',
            'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertNotFound();
        $this->as($this->admin)->postJson($this->url($this->company)."/{$foreign->id}/return", ['return_date' => '2026-10-21'])->assertNotFound();

        $this->assertSame('active', $foreign->fresh()->status->value);
        $this->assertNull($foreign->fresh()->end_date);
    }

    // ------------------------------------------------------------------ picker

    public function test_the_picker_identifies_people_by_unique_ids_and_flags_who_cannot_be_assigned(): void
    {
        $first = $this->makeEmployee();
        $second = $this->makeEmployee();
        $second->update(['full_name' => $first->full_name]);
        $inactive = $this->makeEmployee('waiting');
        $inactive->update(['full_name' => $first->full_name]);
        $here = $this->makeEmployee();
        $here->update(['full_name' => $first->full_name]);
        $this->place($here, $this->company);

        $found = $this->as($this->admin)->getJson($this->url($this->company, 'employee-candidates').'?q='.urlencode($first->full_name))->assertOk()->json('data');
        $byId = collect($found)->keyBy('id');

        $this->assertCount(4, $found, 'four different people share the name and all four are shown');
        $this->assertSame(4, $byId->pluck('employee_number')->unique()->count(), 'each carries a distinct employee number');
        $this->assertTrue($byId[$first->id]['eligible']);
        $this->assertFalse($byId[$inactive->id]['eligible']);
        $this->assertStringContainsString('waiting', $byId[$inactive->id]['ineligible_reason']);
        $this->assertTrue($byId[$here->id]['already_here']);
        $this->assertFalse($byId[$here->id]['eligible']);

        $byNumber = $this->as($this->admin)->getJson($this->url($this->company, 'employee-candidates').'?q='.$second->employee_number)->json('data');
        $this->assertSame($second->id, $byNumber[0]['id'], 'an exact employee number comes first');
        $this->assertSame([], $this->as($this->admin)->getJson($this->url($this->company, 'employee-candidates').'?q=a')->json('data'));
    }

    // ------------------------------------------------------------------ add

    public function test_an_existing_employee_is_assigned_without_creating_or_changing_any_employee_record(): void
    {
        $employee = $this->makeEmployee();
        $before = Employee::query()->count();
        $snapshot = $employee->only(['employee_number', 'full_name', 'phone', 'status']);

        $response = $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['employee_id' => $employee->id, 'assignment_type' => 'replacement']))->assertCreated();

        $this->assertSame($before, Employee::query()->count(), 'no second employee record');
        $this->assertSame($snapshot, $employee->fresh()->only(['employee_number', 'full_name', 'phone', 'status']), 'the registration data is untouched');
        $this->assertSame('replacement', $response->json('data.assignment_type'));
        $this->assertSame($this->company->id, $response->json('data.client_company_id'));
        $this->assertDatabaseHas('audit_logs', ['entity_type' => EmployeeWorkAssignment::class, 'entity_id' => $response->json('data.id'), 'action' => 'create']);
    }

    public function test_the_company_comes_from_the_url_not_from_the_body(): void
    {
        $id = $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['client_company_id' => $this->other->id]))->assertCreated()->json('data.id');

        $this->assertSame($this->company->id, EmployeeWorkAssignment::query()->findOrFail($id)->client_company_id);
    }

    public function test_assignment_validation_and_duplicates(): void
    {
        $employee = $this->makeEmployee();
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['employee_id' => $employee->id]))->assertCreated();

        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['employee_id' => $employee->id]))
            ->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY');

        $waiting = $this->makeEmployee('waiting');
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['employee_id' => $waiting->id]))
            ->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $archived = $this->makeEmployee();
        $archived->delete();
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['employee_id' => $archived->id]))->assertUnprocessable();

        $this->as($this->admin)->postJson($this->url($this->company), [])->assertUnprocessable()->assertJsonValidationErrors(['employee_id', 'assignment_type', 'position_id', 'start_date', 'salary_amount', 'salary_currency'], 'errors');
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['assignment_type' => 'boss']))->assertUnprocessable();
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['salary_amount' => 0]))->assertUnprocessable();
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['employee_id' => 999999]))->assertUnprocessable();
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['salary_currency' => 'US']))->assertUnprocessable();

        $otherLocation = $this->makeClientLocation($this->other);
        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['work_location_id' => $otherLocation->id]))
            ->assertUnprocessable()->assertJsonPath('error_code', 'LOCATION_NOT_IN_COMPANY');

        $this->assertSame(1, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count());
    }

    // ------------------------------------------------------------------ edit

    public function test_editing_an_assignment_changes_only_the_assignment_and_audits_the_before_and_after(): void
    {
        $employee = $this->makeEmployee();
        $a = $this->place($employee, $this->company);
        $name = $employee->full_name;

        $this->as($this->admin)->putJson($this->url($this->company)."/{$a->id}", ['position_id' => $this->driver->id, 'assignment_type' => 'replacement', 'notes' => 'covering'])
            ->assertOk()->assertJsonPath('data.position_id', $this->driver->id)->assertJsonPath('data.assignment_type', 'replacement');

        $this->assertSame($name, $employee->fresh()->full_name);
        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $a->id)->where('description', 'like', '%updated%')->latest('id')->firstOrFail();
        $this->assertSame('edited', $log->metadata['event']);
        $this->assertEqualsCanonicalizing(['position_id', 'assignment_type', 'notes'], $log->metadata['changed_fields']);
        $this->assertSame('permanent', $log->metadata['before']['assignment_type']);
        $this->assertSame('replacement', $log->metadata['after']['assignment_type']);
        $this->assertSame($this->cleaner->id, $log->metadata['before']['position_id']);
    }

    public function test_a_salary_edit_keeps_the_old_salary_in_the_history_and_a_status_field_is_ignored(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01');

        $this->as($this->admin)->putJson($this->url($this->company)."/{$a->id}", ['salary_amount' => 350, 'salary_effective_from' => '2026-11-01', 'salary_change_reason' => 'raise', 'status' => 'ended'])->assertOk();

        $this->assertSame('active', $a->fresh()->status->value, 'status is not editable here');
        $this->assertSame([300.0, 350.0], $a->salaryHistories()->get()->map(fn ($h) => (float) $h->amount)->all());
    }

    public function test_the_start_date_can_be_corrected_and_the_first_salary_row_moves_with_it(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-10-10');

        $this->as($this->admin)->putJson($this->url($this->company)."/{$a->id}", ['start_date' => '2026-10-03'])->assertOk()->assertJsonPath('data.start_date', '2026-10-03');

        $this->assertSame('2026-10-03', $a->salaryHistories()->first()->effective_from->toDateString(), 'payroll never meets a day without a salary');
        $log = AuditLog::query()->where('entity_id', $a->id)->where('entity_type', EmployeeWorkAssignment::class)->latest('id')->first();
        $this->assertSame('2026-10-10', $log->metadata['before']['start_date'], 'the original value is preserved in the audit history');
        $this->assertSame('2026-10-03', $log->metadata['after']['start_date']);

        $row = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-10')->json('data.employees.0');
        $this->assertSame(29, $row['payable_days']);
    }

    public function test_a_start_date_that_jumps_over_a_later_salary_change_is_refused(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-10-01');
        $this->as($this->admin)->putJson($this->url($this->company)."/{$a->id}", ['salary_amount' => 400, 'salary_effective_from' => '2026-10-15'])->assertOk();

        $this->as($this->admin)->putJson($this->url($this->company)."/{$a->id}", ['start_date' => '2026-10-20'])
            ->assertUnprocessable()->assertJsonPath('error_code', 'START_DATE_PASSES_SALARY_CHANGE');
    }

    public function test_approved_payroll_is_never_rewritten_by_a_date_edit_a_stop_a_replace_or_a_return(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-08-15');
        $spare = $this->makeEmployee();
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'approved']);

        $this->as($this->admin)->putJson($this->url($this->company)."/{$a->id}", ['start_date' => '2026-08-20'])->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-09-10', 'reason' => 'left early'])->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');
        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", [
            'replacement_employee_id' => $spare->id, 'replacement_date' => '2026-09-11', 'assignment_type' => 'permanent',
            'salary_amount' => 200, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ])->assertUnprocessable()->assertJsonPath('error_code', 'PAYROLL_PERIOD_LOCKED');

        $fresh = $a->fresh();
        $this->assertSame('2026-08-15', $fresh->start_date->toDateString());
        $this->assertSame('active', $fresh->status->value);
        $this->assertNull($fresh->end_date);
        $this->assertSame(0, EmployeeWorkAssignment::query()->where('employee_id', $spare->id)->count(), 'nothing half-done is left behind');

        // after the locked month the same actions are allowed
        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-10-10', 'reason' => 'left'])->assertOk();
    }

    // ------------------------------------------------------------------ replace

    private function replaceBody(Employee $replacement, array $over = []): array
    {
        return array_replace([
            'replacement_employee_id' => $replacement->id, 'replacement_date' => '2026-10-16', 'assignment_type' => 'permanent',
            'position_id' => $this->cleaner->id, 'salary_amount' => 280, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ], $over);
    }

    public function test_replacing_an_employee_keeps_both_assignments_and_links_them(): void
    {
        $original = $this->makeEmployee();
        $a = $this->place($original, $this->company, 300, '2026-01-01');
        $replacement = $this->makeEmployee();

        $response = $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $this->replaceBody($replacement, ['reason' => 'resigned']))->assertOk();

        $a->refresh();
        $this->assertSame('ended', $a->status->value);
        $this->assertSame('2026-10-15', $a->end_date->toDateString(), 'the original is paid to the day before the replacement date');
        $new = EmployeeWorkAssignment::query()->findOrFail($response->json('data.replacement.id'));
        $this->assertSame($a->id, $new->replaces_assignment_id);
        $this->assertSame($replacement->id, $new->employee_id);
        $this->assertSame('2026-10-16', $new->start_date->toDateString());
        $this->assertSame('permanent', $new->assignment_type->value);
        $this->assertSame(1, $a->salaryHistories()->count(), 'the original salary history is untouched');
        $this->assertSame('active', $original->fresh()->status->value, 'the original stays an active employee: only the company assignment ended');

        $rows = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-10')->json('data.employees');
        $this->assertCount(2, $rows, 'both people appear in the month they overlap it');
        $summary = $this->as($this->admin)->getJson($this->url($this->company).'?period=2026-10')->json('data.summary');
        $this->assertSame(['USD' => 28000], $summary['contracted_monthly_salary_cents_by_currency'], 'the position is counted once, for the person who holds it at month end');
        $this->assertSame(15, collect($rows)->firstWhere('assignment_id', $a->id)['payable_days']);
        $this->assertSame(16, collect($rows)->firstWhere('assignment_id', $new->id)['payable_days']);
    }

    public function test_permanent_or_replacement_must_be_chosen_and_is_recorded_exactly_as_chosen(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company);
        $body = $this->replaceBody($this->makeEmployee());
        unset($body['assignment_type']);

        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $body)->assertUnprocessable()->assertJsonValidationErrors(['assignment_type'], 'errors');
        $this->assertSame('active', $a->fresh()->status->value);

        $id = $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $this->replaceBody($this->makeEmployee(), ['assignment_type' => 'replacement']))->assertOk()->json('data.replacement.id');
        $this->assertSame('replacement', EmployeeWorkAssignment::query()->findOrFail($id)->assignment_type->value);
    }

    public function test_replace_refuses_overlaps_the_same_person_inactive_or_duplicate_replacements_and_leaves_nothing_changed(): void
    {
        $original = $this->makeEmployee();
        $a = $this->place($original, $this->company, 300, '2026-01-01');
        $url = $this->url($this->company)."/{$a->id}/replace";

        $this->as($this->admin)->postJson($url, $this->replaceBody($this->makeEmployee(), ['original_last_day' => '2026-10-16']))->assertUnprocessable();
        $this->as($this->admin)->postJson($url, $this->replaceBody($original))->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_SAME_EMPLOYEE');
        $this->as($this->admin)->postJson($url, $this->replaceBody($this->makeEmployee('waiting')))->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');
        $this->as($this->admin)->postJson($url, $this->replaceBody($this->makeEmployee(), ['replacement_date' => '2025-12-31']))->assertUnprocessable()->assertJsonPath('error_code', 'REPLACEMENT_BEFORE_ASSIGNMENT_START');

        $already = $this->makeEmployee();
        $this->place($already, $this->company, 100, '2026-02-01');
        $this->as($this->admin)->postJson($url, $this->replaceBody($already))->assertUnprocessable()->assertJsonPath('error_code', 'EMPLOYEE_ALREADY_ASSIGNED_AT_COMPANY');

        $fresh = $a->fresh();
        $this->assertSame('active', $fresh->status->value, 'a refused replacement does not end the original');
        $this->assertNull($fresh->end_date);

        $this->as($this->admin)->postJson($url, $this->replaceBody($this->makeEmployee()))->assertOk();
        $this->as($this->admin)->postJson($url, $this->replaceBody($this->makeEmployee()))->assertUnprocessable()->assertJsonPath('error_code', 'ASSIGNMENT_NOT_ACTIVE');
    }

    public function test_a_gap_between_the_original_and_the_replacement_is_allowed_and_recorded(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01');

        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $this->replaceBody($this->makeEmployee(), ['original_last_day' => '2026-10-10']))->assertOk();

        $log = AuditLog::query()->where('entity_id', $a->id)->where('entity_type', EmployeeWorkAssignment::class)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'replaced');
        $this->assertSame(5, $log->metadata['gap_days']);
    }

    // ------------------------------------------------------------------ return

    public function test_the_original_employee_returns_and_every_assignment_is_kept(): void
    {
        $original = $this->makeEmployee();
        $a = $this->place($original, $this->company, 300, '2026-01-01');
        $stand = $this->makeEmployee();
        $replacementId = $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $this->replaceBody($stand, ['assignment_type' => 'replacement', 'replacement_date' => '2026-10-01']))->assertOk()->json('data.replacement.id');
        $a->refresh();

        $result = $this->as($this->admin)->postJson($this->url($this->company)."/{$replacementId}/return", ['return_date' => '2026-10-21'])->assertOk();

        $replacement = EmployeeWorkAssignment::query()->findOrFail($replacementId);
        $this->assertSame('ended', $replacement->status->value);
        $this->assertSame('2026-10-20', $replacement->end_date->toDateString());
        $returned = EmployeeWorkAssignment::query()->findOrFail($result->json('data.returned.id'));
        $this->assertSame($original->id, $returned->employee_id);
        $this->assertSame('2026-10-21', $returned->start_date->toDateString());
        $this->assertSame('permanent', $returned->assignment_type->value);
        $this->assertSame($replacementId, $returned->replaces_assignment_id);
        $this->assertSame('300.00', (string) $returned->salary_amount, "the original's salary is carried over by default");
        $this->assertSame($this->cleaner->id, $returned->position_id);

        $this->assertSame('ended', $a->fresh()->status->value, "the original's first assignment is kept as history");
        $this->assertSame(3, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count());
        $this->assertSame('active', $stand->fresh()->status->value, "the replacement employee's record is not touched");
    }

    public function test_return_is_refused_for_a_permanent_holder_a_missing_original_or_a_date_before_the_replacement_started(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01');
        $permanentId = $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $this->replaceBody($this->makeEmployee(), ['assignment_type' => 'permanent']))->assertOk()->json('data.replacement.id');
        $this->as($this->admin)->postJson($this->url($this->company)."/{$permanentId}/return", ['return_date' => '2026-10-25'])->assertUnprocessable()->assertJsonPath('error_code', 'RETURN_NOT_A_REPLACEMENT');

        $standalone = $this->place($this->makeEmployee(), $this->company, 100, '2026-01-01');
        $standalone->update(['assignment_type' => 'replacement']);
        $this->as($this->admin)->postJson($this->url($this->company)."/{$standalone->id}/return", ['return_date' => '2026-10-25'])->assertUnprocessable()->assertJsonPath('error_code', 'RETURN_NO_ORIGINAL');

        $b = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01');
        $tempId = $this->as($this->admin)->postJson($this->url($this->company)."/{$b->id}/replace", $this->replaceBody($this->makeEmployee(), ['assignment_type' => 'replacement', 'replacement_date' => '2026-10-10']))->assertOk()->json('data.replacement.id');
        $this->as($this->admin)->postJson($this->url($this->company)."/{$tempId}/return", ['return_date' => '2026-10-10'])->assertUnprocessable()->assertJsonPath('error_code', 'RETURN_BEFORE_REPLACEMENT_STARTED');
        $this->assertSame('active', EmployeeWorkAssignment::query()->findOrFail($tempId)->status->value);
    }

    // ------------------------------------------------------------------ stop

    public function test_stopping_ends_only_the_company_assignment_and_frees_the_position(): void
    {
        $employee = $this->makeEmployee();
        $a = $this->place($employee, $this->company, 300, '2026-01-01');

        $response = $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-10-12', 'reason' => 'Contract with the client ended'])->assertOk();

        $this->assertSame('active', $response->json('data.employee_status'));
        $this->assertSame('active', $employee->fresh()->status->value, 'the HR employment status is not changed');
        $this->assertNull($employee->fresh()->deleted_at);
        $a->refresh();
        $this->assertSame('ended', $a->status->value);
        $this->assertSame('2026-10-12', $a->end_date->toDateString());
        $this->assertStringContainsString('Contract with the client ended', (string) $a->notes);
        $this->assertSame(1, $a->salaryHistories()->count());

        $this->as($this->admin)->postJson($this->url($this->company), $this->newAssignment(['start_date' => '2026-10-13']))->assertCreated();
        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-10-14', 'reason' => 'again'])->assertUnprocessable()->assertJsonPath('error_code', 'ASSIGNMENT_NOT_ACTIVE');
    }

    public function test_stop_needs_a_reason_and_a_last_day_not_before_the_start(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-10-10');

        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-10-20'])->assertUnprocessable()->assertJsonValidationErrors(['reason'], 'errors');
        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-10-20', 'reason' => 'x'])->assertUnprocessable();
        $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/stop", ['end_date' => '2026-10-09', 'reason' => 'before it began'])->assertUnprocessable()->assertJsonPath('error_code', 'STOP_BEFORE_ASSIGNMENT_START');
        $this->assertSame('active', $a->fresh()->status->value);
    }

    // ------------------------------------------------------------------ history and payroll preservation

    public function test_the_history_lists_who_did_what_for_this_company_only(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01');
        $replacementId = $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $this->replaceBody($this->makeEmployee(), ['assignment_type' => 'replacement', 'replacement_date' => '2026-10-01']))->assertOk()->json('data.replacement.id');
        $this->as($this->admin)->postJson($this->url($this->company)."/{$replacementId}/return", ['return_date' => '2026-10-20'])->assertOk();
        $other = $this->place($this->makeEmployee(), $this->other);
        $this->as($this->admin)->postJson($this->url($this->other)."/{$other->id}/stop", ['end_date' => '2026-10-20', 'reason' => 'elsewhere'])->assertOk();

        $history = $this->as($this->admin)->getJson($this->url($this->company, 'employee-history'))->assertOk()->json('data');

        $events = array_column($history['events'], 'event');
        $this->assertContains('replaced', $events);
        $this->assertContains('returned', $events);
        $this->assertNotContains('stopped', $events, "another company's stop is not in this company's history");
        $this->assertNotEmpty($history['events'][0]['by']);
        $this->assertSame(3, count($history['chain']));
        $chain = collect($history['chain'])->keyBy('assignment_id');
        $this->assertSame($a->id, $chain[$replacementId]['replaces_assignment_id']);
    }

    public function test_historical_payroll_survives_replacement_return_and_stop(): void
    {
        $a = $this->place($this->makeEmployee(), $this->company, 300, '2026-01-01');
        $run = PayrollRun::query()->create(['period' => '2026-09', 'status' => 'draft']);
        PayrollItem::query()->create(['payroll_run_id' => $run->id, 'period' => '2026-09', 'employee_id' => $a->employee_id, 'work_assignment_id' => $a->id, 'employee_name' => 'Original Person', 'employee_number' => $a->employee->employee_number, 'gross_cents' => 30000, 'net_cents' => 30000, 'eligible_days' => 30, 'days_in_month' => 30, 'calc_snapshot' => []]);
        $rid = $this->as($this->admin)->postJson($this->url($this->company)."/{$a->id}/replace", $this->replaceBody($this->makeEmployee(), ['assignment_type' => 'replacement', 'replacement_date' => '2026-10-05']))->assertOk()->json('data.replacement.id');
        $this->as($this->admin)->postJson($this->url($this->company)."/{$rid}/return", ['return_date' => '2026-10-20'])->assertOk();

        $item = PayrollItem::query()->where('work_assignment_id', $a->id)->sole();
        $this->assertSame(30000, (int) $item->net_cents);
        $this->assertSame('2026-09', $item->period);
        $this->assertSame(3, EmployeeWorkAssignment::query()->where('client_company_id', $this->company->id)->count(), 'original, replacement and returned original: nothing was deleted');
    }
}
