<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * Decision 3 (2026-10-13): an OPTIONAL payment/account number, separate from the registration phone, never
 * required, never used for identity matching, and not disclosed in lists, reports, logs or audit entries.
 */
class EmployeePaymentAccountNumberTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    private const BASE = '/api/v1/admin/hr/employees';

    private function manager(): string
    {
        return $this->tokenWithOnly(['hr.view', 'hr.manage']);
    }

    /** @return array<string, mixed> */
    private function registration(array $overrides = []): array
    {
        return array_replace([
            'full_name' => 'Hodan Cabdi Nuur',
            'phone' => '0615123456',
            'gender' => 'female',
            'location' => 'Hodan',
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id,
            'application_date' => '2026-10-01',
        ], $overrides);
    }

    // ---------------------------------------------------------------- schema and optionality

    public function test_the_column_exists_is_optional_and_existing_employees_keep_null(): void
    {
        $this->assertTrue(Schema::hasColumn('employees', 'payment_account_number'));
        $existing = $this->makeEmployee('active');

        $this->assertNull($existing->fresh()->payment_account_number);
        $this->assertNotNull($existing->fresh()->phone, 'The registration phone is a different column and is untouched.');
    }

    public function test_registering_without_an_account_number_works_and_stays_empty(): void
    {
        $this->as($this->manager())->postJson(self::BASE, $this->registration())
            ->assertCreated()->assertJsonPath('data.payment_account_number', null);

        $this->assertNull(Employee::query()->firstOrFail()->payment_account_number);
    }

    public function test_registering_with_an_account_number_stores_it_separately_from_the_phone(): void
    {
        $this->as($this->manager())->postJson(self::BASE, $this->registration(['payment_account_number' => '+252 61 999 8877']))
            ->assertCreated()
            ->assertJsonPath('data.payment_account_number', '+252 61 999 8877')
            ->assertJsonPath('data.phone', '0615123456');

        $employee = Employee::query()->firstOrFail();
        $this->assertSame('+252 61 999 8877', $employee->payment_account_number);
        $this->assertSame('0615123456', $employee->phone);
        $this->assertNull($employee->alternate_phone);
    }

    public function test_an_empty_string_is_stored_as_empty_not_as_a_value(): void
    {
        $this->as($this->manager())->postJson(self::BASE, $this->registration(['payment_account_number' => '']))->assertCreated();

        $this->assertNull(Employee::query()->firstOrFail()->payment_account_number);
    }

    // ---------------------------------------------------------------- updating and preserving

    public function test_it_can_be_set_changed_and_cleared_on_an_existing_employee(): void
    {
        $token = $this->manager();
        $employee = $this->makeEmployee('active');

        $this->as($token)->putJson(self::BASE."/{$employee->id}", ['payment_account_number' => 'ACC-1001'])->assertOk()->assertJsonPath('data.payment_account_number', 'ACC-1001');
        $this->assertSame('ACC-1001', $employee->fresh()->payment_account_number);

        $this->as($token)->putJson(self::BASE."/{$employee->id}", ['payment_account_number' => 'ACC-2002'])->assertOk();
        $this->assertSame('ACC-2002', $employee->fresh()->payment_account_number);

        $this->as($token)->putJson(self::BASE."/{$employee->id}", ['payment_account_number' => null])->assertOk()->assertJsonPath('data.payment_account_number', null);
        $this->assertNull($employee->fresh()->payment_account_number);
    }

    public function test_an_unrelated_edit_or_status_change_never_clears_an_existing_account_number(): void
    {
        $token = $this->manager();
        $employee = $this->makeEmployee('waiting');
        $employee->update(['payment_account_number' => 'KEEP-ME-123']);

        $this->as($token)->putJson(self::BASE."/{$employee->id}", ['notes' => 'edited something else'])->assertOk();
        $this->assertSame('KEEP-ME-123', $employee->fresh()->payment_account_number, 'Omitting the field keeps the value.');

        $this->as($token)->patchJson(self::BASE."/{$employee->id}/status", ['status' => 'active'])->assertOk();
        $this->assertSame('KEEP-ME-123', $employee->fresh()->payment_account_number);
    }

    // ---------------------------------------------------------------- validation

    public function test_the_format_and_length_are_validated(): void
    {
        $token = $this->manager();
        $employee = $this->makeEmployee('active');

        foreach (['<script>alert(1)</script>', 'bad"quote', "line\nbreak", str_repeat('9', 51), 'semi;colon'] as $bad) {
            $this->as($token)->putJson(self::BASE."/{$employee->id}", ['payment_account_number' => $bad])
                ->assertStatus(422)->assertJsonPath('error_code', 'VALIDATION_ERROR')->assertJsonValidationErrors('payment_account_number', 'errors');
        }

        $this->as($token)->putJson(self::BASE."/{$employee->id}", ['payment_account_number' => ['array']])->assertStatus(422);
        $this->as($token)->postJson(self::BASE, $this->registration(['payment_account_number' => str_repeat('9', 51)]))->assertStatus(422);
        $this->assertNull($employee->fresh()->payment_account_number);

        foreach (['615123456', '+252 615 123 456', 'BANK-001/2026', 'EVC 61.512.3456', str_repeat('9', 50)] as $good) {
            $this->as($token)->putJson(self::BASE."/{$employee->id}", ['payment_account_number' => $good])->assertOk();
        }
    }

    // ---------------------------------------------------------------- authorization and non-disclosure

    public function test_only_callers_who_manage_hr_receive_it_and_only_on_the_single_employee_endpoints(): void
    {
        $employee = $this->makeEmployee('waiting');
        $employee->update(['payment_account_number' => 'SECRET-ACC-123']);

        $viewOnly = $this->tokenWithOnly(['hr.view']);
        $this->as($viewOnly)->getJson(self::BASE."/{$employee->id}")->assertOk()->assertJsonMissingPath('data.payment_account_number');

        $this->as($this->manager())->getJson(self::BASE."/{$employee->id}")->assertOk()->assertJsonPath('data.payment_account_number', 'SECRET-ACC-123');

        [, $super] = $this->importSuperAdmin();
        $this->as($super)->getJson(self::BASE."/{$employee->id}")->assertOk()->assertJsonPath('data.payment_account_number', 'SECRET-ACC-123');

        // Never in lists or reports, even for a Super Admin.
        $list = $this->as($super)->getJson(self::BASE)->assertOk();
        $this->assertStringNotContainsString('SECRET-ACC-123', $list->getContent());
        $this->assertStringNotContainsString('payment_account_number', $list->getContent());
        $roster = $this->as($super)->getJson('/api/v1/admin/hr/reports/waiting-roster')->assertOk();
        $this->assertStringNotContainsString('SECRET-ACC-123', $roster->getContent());
        $this->assertStringNotContainsString('payment_account_number', $roster->getContent());

        // Nor in action responses.
        $status = $this->as($super)->patchJson(self::BASE."/{$employee->id}/status", ['status' => 'active'])->assertOk();
        $this->assertStringNotContainsString('SECRET-ACC-123', $status->getContent());
    }

    public function test_a_read_only_hr_user_cannot_set_it(): void
    {
        $employee = $this->makeEmployee('active');

        $this->as($this->tokenWithOnly(['hr.view']))->putJson(self::BASE."/{$employee->id}", ['payment_account_number' => 'ACC-1'])->assertForbidden();
        $this->as($this->noPermissionToken())->postJson(self::BASE, $this->registration(['payment_account_number' => 'ACC-1']))->assertForbidden();
        $this->assertNull($employee->fresh()->payment_account_number);
    }

    public function test_it_never_appears_in_audit_entries_serialisation_or_application_logs(): void
    {
        $token = $this->manager();
        $created = $this->as($token)->postJson(self::BASE, $this->registration(['payment_account_number' => 'SECRET-ACC-123']))->assertCreated()->json('data.id');
        $this->as($token)->putJson(self::BASE."/{$created}", ['payment_account_number' => 'SECRET-ACC-456', 'notes' => 'x'])->assertOk();

        $this->assertStringNotContainsString('SECRET-ACC', json_encode(DB::table('audit_logs')->get()), 'No audit description or metadata holds the value.');

        $employee = Employee::query()->findOrFail($created);
        $this->assertSame('SECRET-ACC-456', $employee->payment_account_number, 'The attribute itself is readable.');
        $this->assertArrayNotHasKey('payment_account_number', $employee->toArray());
        $this->assertStringNotContainsString('SECRET-ACC', $employee->toJson());
        $this->assertStringNotContainsString('SECRET-ACC', json_encode(DB::table('employee_status_histories')->get()));
    }

    // ---------------------------------------------------------------- separation from identity matching

    public function test_the_account_number_is_never_used_for_identity_or_duplicate_matching(): void
    {
        $token = $this->manager();
        $holder = $this->makeEmployee('active', 'permanent', '615000001');
        $holder->update(['payment_account_number' => '615123456']);

        // Someone registers with a REGISTRATION phone equal to that account number: not a duplicate.
        $this->as($token)->postJson(self::BASE, $this->registration(['phone' => '0615123456']))->assertCreated();

        // The duplicate check on that number finds nobody (it only reads phone and alternate phone).
        $this->as($token)->getJson(self::BASE.'/duplicate-check?phone=615123456')->assertOk()->assertJsonPath('data.0.employee_number', Employee::query()->where('phone', '0615123456')->value('employee_number'));
        $matches = $this->as($token)->getJson(self::BASE.'/duplicate-check?phone=615123456')->json('data');
        $this->assertCount(1, $matches);
        $this->assertNotSame($holder->employee_number, $matches[0]['employee_number'], 'The account holder is not matched by their account number.');
    }

    public function test_setting_an_account_equal_to_someone_elses_phone_raises_no_duplicate_warning(): void
    {
        $token = $this->manager();
        $a = $this->makeEmployee('active', 'permanent', '615777888');
        $b = $this->makeEmployee('active', 'permanent', '615000002');

        $this->as($token)->putJson(self::BASE."/{$b->id}", ['payment_account_number' => '615777888'])->assertOk();

        $this->assertSame('615777888', $b->fresh()->payment_account_number);
        $this->assertSame('615777888', $a->fresh()->phone);
        $this->assertSame('615000002', $b->fresh()->phone, 'The registration phone did not change.');
    }

    public function test_two_employees_may_share_an_account_number(): void
    {
        $token = $this->manager();
        $a = $this->makeEmployee('active');
        $b = $this->makeEmployee('active');

        $this->as($token)->putJson(self::BASE."/{$a->id}", ['payment_account_number' => 'FAMILY-ACC-1'])->assertOk();
        $this->as($token)->putJson(self::BASE."/{$b->id}", ['payment_account_number' => 'FAMILY-ACC-1'])->assertOk();

        $this->assertSame(2, Employee::query()->where('payment_account_number', 'FAMILY-ACC-1')->count());
    }

    public function test_the_registration_import_stores_it_but_never_overwrites_an_existing_employee(): void
    {
        [, $super] = $this->importSuperAdmin();
        $existing = $this->makeEmployee('active', 'permanent', '615123456');
        $existing->update(['full_name' => 'Hodan Cabdi Nuur', 'payment_account_number' => 'ORIGINAL-ACC']);

        $header = ['full_name', 'phone', 'gender', 'location', 'category', 'application_date', 'payment account'];
        $category = EmployeeCategory::query()->firstOrFail()->name;
        $id = $this->stageImport($super, 'employee_registrations', $this->csvUpload($header, [
            ['Hodan Cabdi Nuur', '0615123456', 'female', 'Hodan', $category, '2026-10-01', 'NEW-ACC-SHOULD-NOT-APPLY'],
            ['Brand New Person', '0615999111', 'male', 'Hodan', $category, '2026-10-01', 'NEW-ACC-OK'],
        ]));
        $this->runImport($super, $id)->assertJsonPath('data.counts.imported', 1)->assertJsonPath('data.counts.duplicate', 1);

        $this->assertSame('ORIGINAL-ACC', $existing->fresh()->payment_account_number, 'An existing employee is never updated by an import.');
        $this->assertSame('NEW-ACC-OK', Employee::query()->where('full_name', 'Brand New Person')->firstOrFail()->payment_account_number);
    }
}
