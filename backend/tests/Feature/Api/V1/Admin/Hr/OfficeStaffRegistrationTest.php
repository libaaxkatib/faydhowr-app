<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeGuarantor;
use App\Models\EmployeeWorkAssignment;
use App\Models\Position;
use App\Models\WorkLocation;
use App\Support\Hr\Payroll\PaymentNumber;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The Office Staff workflow: register a NEW person straight into Office Staff, or add an EXISTING employee,
 * with employee number, emergency contact, guarantor, position and monthly salary - and nothing else changing
 * (no payroll run, no payment, no second employee record, no silent overwrite).
 */
class OfficeStaffRegistrationTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/hr/office-staff';

    private string $token;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        [, $this->token] = $this->superAdminToken();
        $this->position = Position::query()->create(['name' => 'Receptionist']);
    }

    /** Laravel caches the first authenticated user for a whole test; reset it so each token really is that user. */
    private function asToken(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @return array<string, mixed> */
    private function newStaff(array $overrides = []): array
    {
        return array_merge([
            'full_name' => 'Amina Office Test',
            'phone' => '0615111222',
            'position_id' => $this->position->id,
            'monthly_salary' => '350.50',
            'start_date' => '2026-10-01',
            'emergency_contact_name' => 'Hodan Emergency',
            'emergency_contact_phone' => '0615333444',
            'guarantor_name' => 'Yusuf Guarantor',
            'guarantor_phone' => '0615555666',
            'guarantor_relationship' => 'Uncle',
        ], $overrides);
    }

    /** @return array<string, mixed> */
    private function existingStaff(Employee $employee, array $overrides = []): array
    {
        return array_merge($this->newStaff(), ['employee_id' => $employee->id], $overrides);
    }

    private function office(): WorkLocation
    {
        return WorkLocation::query()->where('location_type', 'office')->firstOrFail();
    }

    private function money(): array
    {
        return ['runs' => DB::table('payroll_runs')->count(), 'items' => DB::table('payroll_items')->count(), 'payments' => DB::table('employee_payments')->count()];
    }

    // ---- register NEW ------------------------------------------------------------------------------------

    public function test_a_new_office_staff_is_saved_with_every_required_detail_and_goes_straight_to_active(): void
    {
        $before = $this->money();

        $response = $this->asToken($this->token)->postJson(self::BASE, $this->newStaff())->assertCreated();

        $employee = Employee::query()->where('full_name', 'Amina Office Test')->firstOrFail();
        $this->assertSame('active', $employee->status->value);
        $this->assertNull($employee->pipeline_stage, 'never passes through the recruitment pipeline');
        $this->assertFalse($employee->guarantor_needed, 'not placed in the Damiin queue');
        $this->assertMatchesRegularExpression('/^EMP-\d{6}$/', $employee->employee_number);
        $this->assertSame('Hodan Emergency', $employee->secondary_contact_name);
        $this->assertSame('0615333444', $employee->secondary_contact_phone);
        $this->assertSame($this->position->id, $employee->position_id);

        $guarantor = EmployeeGuarantor::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame(['Yusuf Guarantor', '0615555666', 'Uncle'], [$guarantor->guarantor_name, $guarantor->guarantor_phone, $guarantor->relationship]);

        $assignment = EmployeeWorkAssignment::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame($this->office()->id, $assignment->work_location_id);
        $this->assertNull($assignment->client_company_id);
        $this->assertSame($this->position->id, $assignment->position_id);
        $this->assertEquals(350.50, (float) $assignment->salary_amount);
        $this->assertSame(['USD', 'monthly'], [$assignment->salary_currency, $assignment->salary_frequency->value]);
        $this->assertDatabaseHas('assignment_salary_histories', ['work_assignment_id' => $assignment->id, 'amount' => 350.50]);

        $this->assertDatabaseHas('employee_status_histories', ['employee_id' => $employee->id, 'from_status' => null, 'to_status' => 'active', 'source' => 'office_staff']);
        $this->assertSame($before, $this->money(), 'saving a salary creates no payroll run, no payroll line and no payment');

        $response->assertJsonPath('data.employee_number', $employee->employee_number)
            ->assertJsonPath('data.emergency_contact.name', 'Hodan Emergency')
            ->assertJsonPath('data.guarantor.name', 'Yusuf Guarantor')
            ->assertJsonPath('data.current_salary.amount', '350.50')
            ->assertJsonPath('data.position_name', 'Receptionist')
            ->assertJsonPath('data.is_office_staff', true);
    }

    public function test_the_employee_number_follows_the_existing_sequence_and_is_never_reused(): void
    {
        $this->makeEmployee()->forceFill(['employee_number' => 'EMP-000041'])->save();

        $first = $this->asToken($this->token)->postJson(self::BASE, $this->newStaff())->assertCreated()->json('data.employee_number');
        $second = $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['full_name' => 'Second Person', 'phone' => '0615999000']))->assertCreated()->json('data.employee_number');

        $this->assertSame(['EMP-000042', 'EMP-000043'], [$first, $second]);
    }

    /** @return array<string, array{0: string}> */
    public static function requiredFields(): array
    {
        return array_combine(
            $f = ['full_name', 'phone', 'position_id', 'monthly_salary', 'start_date', 'emergency_contact_name', 'emergency_contact_phone', 'guarantor_name', 'guarantor_phone'],
            array_map(fn (string $name): array => [$name], $f),
        );
    }

    #[DataProvider('requiredFields')]
    public function test_each_required_field_is_enforced_and_nothing_is_saved(string $field): void
    {
        $payload = $this->newStaff();
        unset($payload[$field]);

        $this->asToken($this->token)->postJson(self::BASE, $payload)->assertStatus(422)->assertJsonValidationErrors($field);

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('employee_work_assignments', 0);
    }

    public function test_blank_values_count_as_missing(): void
    {
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['emergency_contact_name' => '   ', 'guarantor_name' => '']))
            ->assertStatus(422)->assertJsonValidationErrors(['emergency_contact_name', 'guarantor_name']);
    }

    /** @return array<string, array{0: mixed}> */
    public static function invalidSalaries(): array
    {
        return ['negative' => ['-5'], 'text' => ['abc'], 'three decimals' => ['10.999'], 'too large' => ['100000000'], 'empty' => [''], 'array' => [['1']]];
    }

    #[DataProvider('invalidSalaries')]
    public function test_an_invalid_salary_is_refused(mixed $salary): void
    {
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['monthly_salary' => $salary]))
            ->assertStatus(422)->assertJsonValidationErrors('monthly_salary');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_valid_salaries_including_zero_are_accepted(): void
    {
        foreach (['0', '0.00', '120', '1999.99'] as $i => $salary) {
            $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['monthly_salary' => $salary, 'phone' => '06150000'.$i.'0', 'full_name' => "Person {$i}"]))->assertCreated();
        }
        $this->assertDatabaseCount('employees', 4);
    }

    public function test_phone_numbers_must_look_like_phone_numbers(): void
    {
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['phone' => 'abc', 'emergency_contact_phone' => '123', 'guarantor_phone' => 'none']))
            ->assertStatus(422)->assertJsonValidationErrors(['phone', 'emergency_contact_phone', 'guarantor_phone']);
    }

    public function test_an_unknown_position_is_refused(): void
    {
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['position_id' => 999999]))
            ->assertStatus(422)->assertJsonValidationErrors('position_id');
    }

    public function test_a_phone_that_already_belongs_to_an_employee_is_a_duplicate_unless_confirmed_with_a_reason(): void
    {
        $existing = $this->makeEmployee('active', 'permanent', '0615111222');

        $blocked = $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['phone' => '+252 615 111 222']))
            ->assertStatus(422)->assertJsonPath('error_code', 'DUPLICATE_EMPLOYEE_PHONE');
        $this->assertSame($existing->id, $blocked->json('errors.matches.0.id'));
        $this->assertDatabaseCount('employees', 1);

        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['confirm_duplicate' => true]))
            ->assertStatus(422)->assertJsonValidationErrors('duplicate_reason');

        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['confirm_duplicate' => true, 'duplicate_reason' => 'Different person sharing the family phone.']))->assertCreated();
        $this->assertDatabaseCount('employees', 2);
    }

    public function test_an_archived_employee_with_the_same_phone_is_still_a_duplicate(): void
    {
        $this->makeEmployee('inactive', 'permanent', '0615111222')->delete();

        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff())->assertStatus(422)->assertJsonPath('error_code', 'DUPLICATE_EMPLOYEE_PHONE');
    }

    public function test_when_the_office_is_full_nothing_is_left_half_registered(): void
    {
        $office = $this->office();
        $office->forceFill(['capacity' => 1])->save();
        $this->makeAssignment($this->makeEmployee(), null, $office);

        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff())
            ->assertStatus(422)->assertJsonPath('error_code', 'WORK_LOCATION_CAPACITY_EXCEEDED');

        $this->assertDatabaseCount('employees', 1);
        $this->assertDatabaseCount('employee_guarantors', 0);
        $this->assertDatabaseMissing('employees', ['full_name' => 'Amina Office Test']);
    }

    public function test_the_new_office_staff_appears_in_the_office_list_and_dashboard_count(): void
    {
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff())->assertCreated();

        $list = $this->asToken($this->token)->getJson('/api/v1/admin/hr/employees?office_only=true')->assertOk();
        $this->assertSame(['Amina Office Test'], array_column($list->json('data'), 'full_name'));
        $this->asToken($this->token)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->assertJsonPath('data.office_staff_count', 1);
    }

    // ---- the dedicated Office Staff category -------------------------------------------------------------

    public function test_a_new_office_staff_member_always_gets_the_office_staff_category_and_a_sent_category_is_ignored(): void
    {
        $other = EmployeeCategory::query()->where('name', 'Cooking')->firstOrFail();

        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['employee_category_id' => $other->id]))->assertCreated()
            ->assertJsonPath('data.category_name', 'Office Staff');

        $this->assertSame(EmployeeCategory::officeStaff()->id, Employee::query()->where('full_name', 'Amina Office Test')->value('employee_category_id'));
    }

    public function test_registration_refuses_to_guess_when_the_office_staff_category_is_missing(): void
    {
        // Only possible if the migration has not run; nothing else may be used instead.
        EmployeeCategory::officeStaff()->delete();

        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff())
            ->assertStatus(422)->assertJsonPath('error_code', 'OFFICE_STAFF_CATEGORY_MISSING');
        $this->assertDatabaseCount('employees', 0);
    }

    public function test_adding_an_existing_employee_never_changes_their_category(): void
    {
        $cooking = EmployeeCategory::query()->where('name', 'Cooking')->firstOrFail();
        $employee = $this->makeEmployee();
        $employee->forceFill(['employee_category_id' => $cooking->id])->save();

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee))->assertOk()
            ->assertJsonPath('data.category_name', 'Cooking');

        $this->assertSame($cooking->id, $employee->refresh()->employee_category_id);
    }

    public function test_office_staff_counts_filters_breakdown_and_payroll_stay_consistent(): void
    {
        $existing = $this->makeEmployee();
        $existingCategory = $existing->employee_category_id;
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['monthly_salary' => '400']))->assertCreated();
        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($existing, ['monthly_salary' => '250']))->assertOk();
        $officeCategory = EmployeeCategory::officeStaff()->id;

        // Office Staff count follows the office ASSIGNMENT (both people), not the category (only the new one).
        $dashboard = $this->asToken($this->token)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
        $this->assertSame(2, $dashboard['office_staff_count']);
        $this->assertSame(2, $this->asToken($this->token)->getJson('/api/v1/admin/hr/employees?office_only=true')->json('meta.total'));

        // The category breakdown and the category filter agree, and the other categories are unchanged.
        $breakdown = collect($dashboard['category_breakdown'])->pluck('total', 'name');
        $this->assertSame(1, $breakdown['Office Staff']);
        $this->assertSame(1, $breakdown[EmployeeCategory::query()->find($existingCategory)->name]);
        $this->assertSame(1, $this->asToken($this->token)->getJson("/api/v1/admin/hr/employees?employee_category_id={$officeCategory}")->json('meta.total'));
        $this->assertSame(0, $breakdown['Cooking']);

        // Payroll ignores categories: both salaries are in the live month calculation, and nothing was created.
        $rollup = $this->asToken($this->token)->getJson('/api/v1/admin/hr/reports/payroll-rollup?period=2026-10')->assertOk();
        $this->assertSame('650.00', $rollup->json('meta.totals.net_payable'));
        $this->assertDatabaseCount('payroll_runs', 0);
    }

    public function test_office_staff_work_with_the_payment_report_the_client_list_and_the_dashboard_together(): void
    {
        $existing = $this->makeEmployee('active', 'permanent', '0615777888');
        $existing->forceFill(['payment_account_number' => '0699000111'])->save();
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff(['monthly_salary' => '400']))->assertCreated();
        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($existing, ['monthly_salary' => '250']))->assertOk();

        // Payment Excel: both people are listed once, under the internal office (not as a client), with their real amounts.
        $response = $this->asToken($this->token)->get('/api/v1/admin/hr/reports/monthly-payment-list?period=2026-10')->assertOk();
        $path = $response->baseResponse->getFile()->getPathname();
        $book = IOFactory::load($path);
        @unlink($path);
        $rows = $book->getSheetByName('Employees')->toArray(null, true, false, false);
        $people = collect(array_slice($rows, 4))->filter(fn (array $r): bool => $r[0] !== null && $r[1] !== null)->map(fn (array $r): array => array_combine($rows[3], $r))->keyBy('Employee name');
        $this->assertCount(2, $people);
        foreach ($people as $row) {
            $this->assertSame(['Fayadhowr Office', 'Office (internal)'], [$row['Company / Guri'], $row['Company category']]);
        }
        $this->assertEquals([400.0, 250.0], [$people['Amina Office Test']['Payment amount (USD)'], $people[$existing->full_name]['Payment amount (USD)']]);
        $this->assertSame('0699000111', $people[$existing->full_name]['Payment / account number']);
        // The Office Staff form does not collect a payment number, so a new hire shows the registration phone, clearly labelled as a fallback, until the real number is recorded.
        $this->assertSame('0615111222', $people['Amina Office Test']['Payment / account number']);
        $this->assertSame(PaymentNumber::LABEL_PHONE_FALLBACK, $people['Amina Office Test']['Payment number source']);
        $this->assertEquals(650.0, $book->getSheetByName('Summary')->getCell('B7')->getValue());

        // Companies & Workplaces: the office staff never appear as clients and the Company/Guri counts are untouched.
        $companies = $this->asToken($this->token)->getJson('/api/v1/admin/hr/client-companies')->assertOk()->json('data');
        $this->assertSame([], $companies);
        $this->assertDatabaseMissing('client_companies', ['name' => 'Fayadhowr Office']);

        // Dashboard cards link to lists that match their numbers.
        $dashboard = $this->asToken($this->token)->getJson('/api/v1/admin/hr/dashboard')->assertOk()->json('data');
        $this->assertSame([2, 2], [$dashboard['office_staff_count'], $this->asToken($this->token)->getJson('/api/v1/admin/hr/employees?office_only=true')->json('meta.total')]);
        $this->assertSame([2, 0], [$dashboard['active_employees'], $dashboard['client_company_active_employees']]);
        $this->assertDatabaseCount('payroll_runs', 0);
    }

    // ---- add EXISTING ------------------------------------------------------------------------------------

    public function test_an_existing_employee_is_added_without_creating_a_duplicate_and_keeps_their_number(): void
    {
        $employee = $this->makeEmployee('active', 'permanent', '0615777888');
        $number = $employee->employee_number;
        $before = $this->money();

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee))->assertOk()
            ->assertJsonPath('data.employee_number', $number)
            ->assertJsonPath('data.emergency_contact.phone', '0615333444')
            ->assertJsonPath('data.guarantor.name', 'Yusuf Guarantor')
            ->assertJsonPath('data.is_office_staff', true);

        $this->assertDatabaseCount('employees', 1);
        $this->assertSame($number, $employee->refresh()->employee_number);
        $this->assertSame('0615777888', $employee->phone, 'registration phone untouched');
        $this->assertSame($this->position->id, $employee->position_id);
        $assignment = $employee->workAssignments()->firstOrFail();
        $this->assertSame([$this->office()->id, '350.50'], [$assignment->work_location_id, number_format((float) $assignment->salary_amount, 2, '.', '')]);
        $this->assertSame($before, $this->money());
    }

    public function test_only_active_employees_can_be_added_and_nothing_changes_otherwise(): void
    {
        $waiting = $this->makeEmployee('waiting');

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($waiting))
            ->assertStatus(422)->assertJsonPath('error_code', 'EMPLOYEE_NOT_ACTIVE');

        $this->assertSame('waiting', $waiting->refresh()->status->value);
        $this->assertNull($waiting->secondary_contact_name);
        $this->assertDatabaseCount('employee_work_assignments', 0);
        $this->assertDatabaseCount('employee_guarantors', 0);
    }

    public function test_adding_someone_who_is_already_office_staff_is_refused(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, null, $this->office());

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee))
            ->assertStatus(422)->assertJsonPath('error_code', 'ALREADY_OFFICE_STAFF');
        $this->assertSame(1, $employee->workAssignments()->count());
    }

    public function test_position_and_salary_are_required_for_an_existing_employee_too(): void
    {
        $employee = $this->makeEmployee();
        $payload = $this->existingStaff($employee);
        unset($payload['position_id'], $payload['monthly_salary']);

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $payload)->assertStatus(422)->assertJsonValidationErrors(['position_id', 'monthly_salary']);
        $this->assertDatabaseCount('employee_work_assignments', 0);
    }

    public function test_existing_details_are_never_overwritten_silently(): void
    {
        $employee = $this->makeEmployee();
        $employee->forceFill(['secondary_contact_name' => 'Old Contact', 'secondary_contact_phone' => '0615000001', 'position_id' => Position::query()->create(['name' => 'Cleaner'])->id])->save();
        EmployeeGuarantor::query()->create(['employee_id' => $employee->id, 'guarantor_name' => 'Old Guarantor', 'guarantor_phone' => '0615000002']);

        $response = $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee))
            ->assertStatus(422)->assertJsonPath('error_code', 'OVERWRITE_NOT_CONFIRMED');

        $this->assertEqualsCanonicalizing(['emergency_contact', 'guarantor', 'position'], array_keys($response->json('errors.conflicts')));
        $employee->refresh();
        $this->assertSame(['Old Contact', '0615000001'], [$employee->secondary_contact_name, $employee->secondary_contact_phone]);
        $this->assertSame('Old Guarantor', $employee->guarantor->guarantor_name);
        $this->assertNotSame($this->position->id, $employee->position_id);
        $this->assertDatabaseCount('employee_work_assignments', 0);

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee, ['confirm_overwrite' => ['emergency_contact', 'guarantor', 'position']]))->assertOk();
        $employee->refresh();
        $this->assertSame(['Hodan Emergency', '0615333444'], [$employee->secondary_contact_name, $employee->secondary_contact_phone]);
        $this->assertSame('Yusuf Guarantor', $employee->guarantor->guarantor_name);
        $this->assertSame($this->position->id, $employee->position_id);
        $this->assertSame(1, EmployeeGuarantor::query()->where('employee_id', $employee->id)->count(), 'the guarantor row is updated, never duplicated');
    }

    public function test_confirming_one_field_does_not_confirm_the_others(): void
    {
        $employee = $this->makeEmployee();
        $employee->forceFill(['secondary_contact_name' => 'Old Contact', 'secondary_contact_phone' => '0615000001'])->save();
        EmployeeGuarantor::query()->create(['employee_id' => $employee->id, 'guarantor_name' => 'Old Guarantor', 'guarantor_phone' => '0615000002']);

        $response = $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee, ['confirm_overwrite' => ['emergency_contact']]))
            ->assertStatus(422)->assertJsonPath('error_code', 'OVERWRITE_NOT_CONFIRMED');

        $this->assertSame(['guarantor'], array_keys($response->json('errors.conflicts')));
        $this->assertSame('Old Contact', $employee->refresh()->secondary_contact_name);
    }

    public function test_re_entering_the_same_details_in_another_format_is_not_a_conflict(): void
    {
        $employee = $this->makeEmployee();
        $employee->forceFill(['secondary_contact_name' => 'hodan  emergency', 'secondary_contact_phone' => '+252 615 333 444', 'position_id' => $this->position->id])->save();
        $guarantor = EmployeeGuarantor::query()->create(['employee_id' => $employee->id, 'guarantor_name' => 'YUSUF GUARANTOR', 'guarantor_phone' => '615-555-666', 'other_info' => 'keep me']);
        $stamp = $guarantor->updated_at;

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee, ['guarantor_relationship' => null]))->assertOk();

        $this->assertSame('keep me', $guarantor->refresh()->other_info);
        $this->assertEquals($stamp, $guarantor->updated_at, 'an unchanged guarantor is not rewritten');
    }

    public function test_an_employee_with_a_company_assignment_needs_an_explicit_confirmation_because_payroll_pays_both(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->makeCompany('Alpha Hospital'), null, 200);

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee))
            ->assertStatus(422)->assertJsonPath('error_code', 'ADDITIONAL_ASSIGNMENT_NOT_CONFIRMED')
            ->assertJsonPath('errors.assignments.0.label', 'Alpha Hospital');
        $this->assertSame(1, $employee->workAssignments()->count());

        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($employee, ['confirm_additional_assignment' => true]))->assertOk();
        $this->assertSame(2, $employee->workAssignments()->count());
        $this->assertEquals(200, (float) $employee->workAssignments()->whereNotNull('client_company_id')->firstOrFail()->salary_amount, 'the existing assignment and its salary are untouched');
    }

    // ---- find existing + documents -----------------------------------------------------------------------

    public function test_an_existing_employee_can_be_found_by_name_number_or_phone_and_shows_their_details(): void
    {
        $employee = $this->makeEmployee('active', 'permanent', '+252 615 777 888');
        $employee->forceFill(['full_name' => 'Zainab Findable', 'secondary_contact_name' => 'Em Contact', 'secondary_contact_phone' => '0615000009', 'payment_account_number' => '0699888777'])->save();
        EmployeeGuarantor::query()->create(['employee_id' => $employee->id, 'guarantor_name' => 'Gua Rantor', 'guarantor_phone' => '0615000010']);
        $this->makeAssignment($employee, $this->makeCompany('Alpha'), null, 275);

        foreach (['zainab', $employee->employee_number, '615777888', '0615 777-888'] as $term) {
            $rows = $this->asToken($this->token)->getJson(self::BASE.'/lookup?search='.urlencode($term))->assertOk()->json('data');
            $this->assertSame([$employee->id], array_column($rows, 'id'), "search: {$term}");
        }

        $row = $this->asToken($this->token)->getJson(self::BASE.'/lookup?search=zainab')->json('data.0');
        $this->assertSame('Em Contact', $row['emergency_contact']['name']);
        $this->assertSame('Gua Rantor', $row['guarantor']['name']);
        $this->assertSame('275.00', $row['current_salary']['amount']);
        $this->assertTrue($row['can_add']);
        $this->assertSame('Alpha', $row['active_assignments'][0]['label']);
        $this->assertStringNotContainsString('0699888777', json_encode($row), 'payment account number is never exposed here');
    }

    public function test_search_needs_two_characters_and_does_not_list_unrelated_people(): void
    {
        $this->makeEmployee();

        $this->asToken($this->token)->getJson(self::BASE.'/lookup?search=a')->assertStatus(422)->assertJsonValidationErrors('search');
        $this->assertSame([], $this->asToken($this->token)->getJson(self::BASE.'/lookup?search=nobodyhasthisname')->json('data'));
    }

    public function test_document_status_shows_what_is_on_file_and_what_is_missing_without_any_public_url(): void
    {
        Storage::fake('local');
        $employee = $this->makeEmployee();
        $identity = DB::table('employee_document_categories')->where('name', 'Identity Documents')->value('id');

        $this->asToken($this->token)->post("/api/v1/admin/hr/employees/{$employee->id}/documents", [
            'file' => UploadedFile::fake()->create('id.pdf', 100, 'application/pdf'),
            'employee_document_category_id' => $identity,
        ], ['Accept' => 'application/json'])->assertCreated();

        $docs = collect($this->asToken($this->token)->getJson(self::BASE."/{$employee->id}")->assertOk()->json('data.documents'))->keyBy('key');

        $this->assertSame(['identity' => 'on_file', 'employment' => 'missing', 'guarantor' => 'missing', 'other' => 'missing'], $docs->map(fn (array $d): string => $d['status'])->all());
        $this->assertSame('id.pdf', $docs['identity']['files'][0]['file_name']);
        $this->assertFalse($docs['other']['required']);
        $this->assertStringNotContainsString('employees/', json_encode($docs->all()), 'no storage path or URL is exposed');
        $this->assertStringNotContainsString('http', json_encode($docs->all()));
    }

    public function test_uploading_into_a_category_that_has_a_file_replaces_it_instead_of_duplicating(): void
    {
        Storage::fake('local');
        $employee = $this->makeEmployee();
        $category = DB::table('employee_document_categories')->where('name', 'Guarantor Documents')->value('id');

        foreach (['first.pdf', 'second.pdf'] as $name) {
            $this->asToken($this->token)->post("/api/v1/admin/hr/employees/{$employee->id}/documents", [
                'file' => UploadedFile::fake()->create($name, 50, 'application/pdf'),
                'employee_document_category_id' => $category,
            ], ['Accept' => 'application/json'])->assertCreated();
        }

        $files = collect($this->asToken($this->token)->getJson(self::BASE."/{$employee->id}")->json('data.documents'))->firstWhere('key', 'guarantor')['files'];
        $this->assertSame(['second.pdf'], array_column($files, 'file_name'), 'one current file per group; the old one is kept as history, not shown as current');
    }

    public function test_document_uploads_keep_the_existing_file_rules_and_private_storage(): void
    {
        Storage::fake('local');
        $employee = $this->makeEmployee();
        $url = "/api/v1/admin/hr/employees/{$employee->id}/documents";

        $this->asToken($this->token)->post($url, ['file' => UploadedFile::fake()->create('virus.exe', 10, 'application/x-msdownload')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asToken($this->token)->post($url, ['file' => UploadedFile::fake()->create('page.php', 10, 'text/x-php')], ['Accept' => 'application/json'])->assertStatus(422);
        $this->asToken($this->token)->post($url, ['file' => UploadedFile::fake()->create('big.pdf', 11 * 1024, 'application/pdf')], ['Accept' => 'application/json'])->assertStatus(422);

        $ok = $this->asToken($this->token)->post($url, ['file' => UploadedFile::fake()->create('ok.pdf', 100, 'application/pdf')], ['Accept' => 'application/json'])->assertCreated();
        Storage::disk('local')->assertExists(DB::table('employee_documents')->value('file_path'));
        $this->assertNotNull($ok->json('data.id'));

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->getJson("/api/v1/admin/hr/employees/{$employee->id}/documents/{$ok->json('data.id')}/download")->assertUnauthorized();
    }

    public function test_each_action_leaves_one_audit_line_and_no_contact_numbers_in_the_log(): void
    {
        $existing = $this->makeEmployee('active', 'permanent', '0615777888');
        $this->asToken($this->token)->postJson(self::BASE, $this->newStaff())->assertCreated();
        $this->asToken($this->token)->postJson(self::BASE.'/assign', $this->existingStaff($existing))->assertOk();

        $lines = DB::table('audit_logs')->where('description', 'like', '%Office Staff%')->pluck('description')->all();
        $this->assertCount(2, $lines, implode(' / ', $lines));

        $everything = json_encode(DB::table('audit_logs')->get(['description', 'metadata'])->all());
        foreach (['0615111222', '0615333444', '0615555666', '0615777888'] as $number) {
            $this->assertStringNotContainsString($number, $everything, 'phone numbers never go to the audit log');
        }
    }

    // ---- access control ----------------------------------------------------------------------------------

    public function test_only_hr_managers_can_use_the_office_staff_workflow(): void
    {
        $employee = $this->makeEmployee();
        $calls = [
            ['getJson', self::BASE.'/lookup?search=ab', null],
            ['getJson', self::BASE."/{$employee->id}", null],
            ['postJson', self::BASE, $this->newStaff()],
            ['postJson', self::BASE.'/assign', $this->existingStaff($employee)],
        ];

        $this->app['auth']->forgetGuards();
        foreach ($calls as [$method, $url, $body]) {
            $this->{$method}($url, ...($body === null ? [] : [$body]))->assertUnauthorized();
        }

        foreach ([[], ['hr.view'], ['hr.view', 'hr.reports.view'], ['hr.payroll.view', 'hr.payroll.manage']] as $permissions) {
            $token = $permissions === [] ? $this->noPermissionToken() : $this->tokenWithOnly($permissions);
            foreach ($calls as [$method, $url, $body]) {
                $this->asToken($token)->{$method}($url, ...($body === null ? [] : [$body]))->assertForbidden();
            }
        }

        $this->assertDatabaseCount('employee_work_assignments', 0);
        $this->assertDatabaseCount('employees', 1);

        $this->asToken($this->tokenWithOnly(['hr.manage']))->getJson(self::BASE.'/lookup?search=ab')->assertOk();
    }
}
