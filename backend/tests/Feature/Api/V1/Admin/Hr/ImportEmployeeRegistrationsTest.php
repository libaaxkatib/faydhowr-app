<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeCategory;
use App\Models\EmployeeStatusHistory;
use App\Models\ImportBatch;
use App\Support\Hr\Import\ImportRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * Phase C: new employee registrations. Required: full name, registration phone, gender, location,
 * category, application date (the StoreEmployeeRequest set).
 */
class ImportEmployeeRegistrationsTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    private const HEADER = ['full_name', 'phone', 'gender', 'location', 'category', 'application_date'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->importSuperAdmin();
    }

    /** @return list<string> */
    private function row(array $overrides = []): array
    {
        $defaults = ['full_name' => 'Hodan Cabdi Nuur', 'phone' => '0615123456', 'gender' => 'female', 'location' => 'Hodan', 'category' => 'Cooking', 'application_date' => '2026-10-01'];

        return array_values(array_replace($defaults, $overrides));
    }

    private function stageRows(array $rows, array $header = self::HEADER): int
    {
        return $this->stageImport($this->token, 'employee_registrations', $this->csvUpload($header, $rows));
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $id): array
    {
        return $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->assertOk()->json('data');
    }

    public function test_a_valid_row_registers_a_new_employee_at_the_application_stage(): void
    {
        $id = $this->stageRows([$this->row()]);
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 1);

        $employee = Employee::query()->firstOrFail();
        $this->assertSame('Hodan Cabdi Nuur', $employee->full_name);
        $this->assertSame('applicant', $employee->status->value, 'Application stage = status Applicant.');
        $this->assertSame('damiin_needed', $employee->pipeline_stage->value);
        $this->assertSame('female', $employee->gender->value);
        $this->assertSame('2026-10-01', $employee->application_date->toDateString());
        $this->assertSame('Cooking', $employee->category->name);
        $this->assertNull($employee->joining_date);
        $this->assertStringStartsWith('EMP-', $employee->employee_number);
        $this->assertSame("Excel import #{$id}", $employee->source);

        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertNull($history->from_status);
        $this->assertSame('applicant', $history->to_status->value);
        $this->assertDatabaseHas('audit_logs', ['entity_id' => $employee->id, 'action' => 'create']);
    }

    public function test_optional_fields_are_preserved(): void
    {
        $header = [...self::HEADER, 'alternate_phone', 'age', 'marital_status', 'lives_with', 'reference_name', 'secondary_contact_name', 'secondary_contact_phone', 'experience', 'source', 'notes'];
        $row = [...$this->row(), '0907111222', '27', 'single', 'family', 'Ref Person', 'Contact Person', '0615999888', '3 years', 'Walk-in', 'Good candidate'];

        $id = $this->stageRows([$row], $header);
        $this->runImport($this->token, $id);

        $employee = Employee::query()->firstOrFail();
        $this->assertSame('0907111222', $employee->alternate_phone);
        $this->assertSame(27, $employee->age);
        $this->assertSame('single', $employee->marital_status);
        $this->assertSame('family', $employee->lives_with);
        $this->assertSame('Ref Person', $employee->reference_name);
        $this->assertSame('Contact Person', $employee->secondary_contact_name);
        $this->assertSame('0615999888', $employee->secondary_contact_phone);
        $this->assertSame('3 years', $employee->experience);
        $this->assertSame('Walk-in', $employee->source, 'A source given in the file is kept.');
        $this->assertSame('Good candidate', $employee->notes);
    }

    public function test_every_required_field_is_enforced_per_row(): void
    {
        $id = $this->stageRows([
            $this->row(['full_name' => '']),
            $this->row(['phone' => '']),
            $this->row(['phone' => '12345']),
            $this->row(['gender' => 'other']),
            $this->row(['location' => '']),
            $this->row(['category' => 'Astronaut']),
            $this->row(['application_date' => '']),
            $this->row(['application_date' => 'next week']),
            $this->row(['application_date' => '2099-01-01']),
        ]);

        $this->assertSame(
            ['NAME_REQUIRED', 'PHONE_INVALID', 'PHONE_INVALID', 'GENDER_INVALID', 'LOCATION_INVALID', 'CATEGORY_UNKNOWN', 'DATE_INVALID', 'DATE_INVALID', 'DATE_IN_FUTURE'],
            array_column($this->rows($id), 'code'),
        );
        $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->assertJsonPath('data.counts.invalid', 9);
    }

    public function test_missing_required_columns_are_refused_for_the_whole_file(): void
    {
        $this->upload($this->token, 'employee_registrations', $this->csvUpload(['full_name', 'phone'], [['A B', '0615123456']]))
            ->assertStatus(422)->assertJsonPath('error_code', 'MISSING_COLUMNS');
    }

    public function test_invalid_rows_are_reported_while_valid_rows_still_import(): void
    {
        $id = $this->stageRows([$this->row(), $this->row(['full_name' => '', 'phone' => '0615222333']), $this->row(['full_name' => 'Second Person', 'phone' => '0615444555'])]);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 2)->assertJsonPath('data.counts.invalid', 1);
        $this->assertSame(2, Employee::query()->count());
    }

    public function test_phone_numbers_and_names_are_normalised_for_matching(): void
    {
        Employee::query()->create([
            'employee_number' => 'EMP-777001', 'full_name' => 'Hodan  Cabdi Nuur', 'phone' => '+252 615 123 456',
            'employee_category_id' => EmployeeCategory::query()->firstOrFail()->id, 'status' => 'applicant', 'application_date' => '2026-01-01',
        ]);

        // Same person: different phone formatting, different case/spacing in the name.
        $id = $this->stageRows([$this->row(['full_name' => 'HODAN cabdi  nuur', 'phone' => '0615-123-456'])]);
        $row = $this->rows($id)[0];

        $this->assertSame('duplicate', $row['status']);
        $this->assertSame('EMPLOYEE_EXISTS', $row['code']);
    }

    public function test_an_existing_employee_is_skipped_and_never_updated(): void
    {
        $existing = $this->makeEmployee('active', 'permanent', '615123456');
        $existing->update(['full_name' => 'Hodan Cabdi Nuur', 'location' => 'Original Place', 'notes' => 'original notes']);
        $before = $existing->fresh()->toArray();
        $historyBefore = EmployeeStatusHistory::query()->count();

        $id = $this->stageRows([$this->row(['location' => 'New Place', 'category' => 'Waiter'])]);
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.duplicate', 1)->assertJsonPath('data.counts.imported', 0);

        $this->assertSame($before, $existing->fresh()->toArray(), 'No field of the existing employee changed.');
        $this->assertSame(1, Employee::query()->count());
        $this->assertSame($historyBefore, EmployeeStatusHistory::query()->count());
    }

    public function test_the_same_phone_with_a_different_name_is_a_suspected_duplicate_for_manual_review(): void
    {
        $existing = $this->makeEmployee('waiting', 'permanent', '615123456');

        $id = $this->stageRows([$this->row(['full_name' => 'Totally Different Person'])]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('PHONE_SHARED_DIFFERENT_NAME', $row['code']);
        $this->assertSame([$existing->employee_number], $row['meta']['employee_numbers']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 0);
        $this->assertSame(1, Employee::query()->count(), 'A suspected duplicate is never created automatically.');
    }

    public function test_the_same_full_name_with_a_different_phone_is_a_suspected_duplicate_for_manual_review(): void
    {
        $existing = $this->makeEmployee('applicant', 'permanent', '615999000');
        $existing->update(['full_name' => 'Hodan Cabdi Nuur']);

        $id = $this->stageRows([$this->row()]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('SAME_NAME_DIFFERENT_PHONE', $row['code']);
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 0);
    }

    public function test_an_archived_employees_phone_still_counts_as_existing(): void
    {
        $archived = $this->makeEmployee('inactive', 'permanent', '615123456');
        $archived->update(['full_name' => 'Hodan Cabdi Nuur']);
        $archived->delete();

        $id = $this->stageRows([$this->row()]);

        $this->assertSame('duplicate', $this->rows($id)[0]['status']);
    }

    public function test_the_same_phone_twice_in_one_file_registers_once(): void
    {
        $id = $this->stageRows([$this->row(), $this->row(['full_name' => 'Hodan Cabdi Nuur', 'phone' => '+252615123456'])]);
        $rows = $this->rows($id);

        $this->assertSame(['valid', 'duplicate'], array_column($rows, 'status'));
        $this->assertSame('DUPLICATE_IN_FILE', $rows[1]['code']);
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 1);
        $this->assertSame(1, Employee::query()->count());
    }

    public function test_an_employee_registered_after_the_preview_is_skipped_at_confirmation(): void
    {
        $id = $this->stageRows([$this->row()]);
        $late = $this->makeEmployee('applicant', 'permanent', '615123456');
        $late->update(['full_name' => 'Hodan Cabdi Nuur']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.skipped', 1)->assertJsonPath('data.counts.imported', 0);
        $this->assertSame(1, Employee::query()->count());
    }

    public function test_the_payment_account_column_is_stored_separately_and_never_treated_as_the_registration_phone(): void
    {
        // The account number looks exactly like another employee's phone. It must not match, flag or replace anything.
        $other = $this->makeEmployee('active', 'permanent', '615777888');
        $header = [...self::HEADER, 'Payment Account'];

        $response = $this->upload($this->token, 'employee_registrations', $this->csvUpload($header, [[...$this->row(['phone' => '0615123456']), '0615777888']]))->assertCreated();

        $this->assertSame([], $response->json('data.unrecognised_headers'), 'The column is recognised now.');
        $id = $response->json('data.id');
        $this->assertSame('valid', $this->rows($id)[0]['status'], 'The payment/account value is not treated as a phone, so there is no duplicate.');

        $this->runImport($this->token, $id);
        $employee = Employee::query()->where('full_name', 'Hodan Cabdi Nuur')->firstOrFail();
        $this->assertSame('0615123456', $employee->phone, 'The registration phone is unchanged.');
        $this->assertSame('0615777888', $employee->payment_account_number, 'The account number is stored in its own field.');
        $this->assertNull($employee->alternate_phone);
        $this->assertSame('615777888', $other->fresh()->phone);
        $this->assertNull($other->fresh()->payment_account_number, 'The other employee was not touched.');
    }

    /**
     * The account number is looked for as the WHOLE value (and its dash-less and digits-only spellings). A short fragment such as "9988" proves
     * nothing: the text searched also holds generated ids and timestamps (on PostgreSQL the sequence ids keep growing during a test run), which
     * can contain any short digit sequence by chance.
     */
    private function assertNoAccountNumber(string $text, string $where): void
    {
        foreach (['ACC-9988-7766', '9988-7766', '99887766'] as $spelling) {
            $this->assertStringNotContainsString($spelling, $text, "The account number ({$spelling}) must not appear in {$where}.");
        }
    }

    public function test_the_payment_account_is_not_shown_in_the_preview_or_report_and_is_purged_with_the_staging_data(): void
    {
        $header = [...self::HEADER, 'account number'];
        $id = $this->stageRows([[...$this->row(), 'ACC-9988-7766']], $header);

        $row = $this->rows($id)[0];
        $this->assertArrayNotHasKey('payment_account_number', $row['details']);
        $this->assertTrue($row['details']['has_payment_account']);
        $this->assertNoAccountNumber(json_encode($row), 'the row preview');

        $csv = $this->as($this->token)->get(self::IMPORTS."/{$id}/report")->assertOk()->streamedContent();
        $this->assertNoAccountNumber($csv, 'the report');

        ImportBatch::query()->whereKey($id)->update(['retention_expires_at' => now('UTC')->subMinute()]);
        app(ImportRetention::class)->purgeExpired();
        $this->assertNoAccountNumber(json_encode(DB::table('import_rows')->get()), 'the staging rows after the purge');
    }

    public function test_an_invalid_payment_account_marks_only_that_row_invalid(): void
    {
        $header = [...self::HEADER, 'payment account'];
        $id = $this->stageRows([
            [...$this->row(['phone' => '0615000001']), '<script>alert(1)</script>'],
            [...$this->row(['full_name' => 'Second Person', 'phone' => '0615000002']), str_repeat('9', 51)],
            [...$this->row(['full_name' => 'Third Person', 'phone' => '0615000003']), '+252 61 5000 003'],
            [...$this->row(['full_name' => 'Fourth Person', 'phone' => '0615000004']), ''],
        ], $header);

        $this->assertSame(['ACCOUNT_INVALID', 'ACCOUNT_INVALID', null, null], array_column($this->rows($id), 'code'));
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 2);
        $this->assertNull(Employee::query()->where('full_name', 'Fourth Person')->firstOrFail()->payment_account_number, 'An empty account stays empty.');
    }

    public function test_dates_in_several_formats_and_excel_serials_are_understood(): void
    {
        $rows = [
            $this->row(['phone' => '0615000001', 'application_date' => '2026-10-01']),
            $this->row(['phone' => '0615000002', 'application_date' => '01/10/2026']),
            $this->row(['phone' => '0615000003', 'application_date' => '1-10-2026']),
            $this->row(['phone' => '0615000004', 'application_date' => '46296']),
            $this->row(['phone' => '0615000005', 'application_date' => '13/13/2026']),
        ];
        $statuses = array_map(fn (array $r): string => $r['status'], $this->rows($this->stageRows($rows)));

        $this->assertSame(['valid', 'valid', 'valid', 'valid', 'invalid'], $statuses);
    }

    public function test_gender_shortcuts_and_category_spellings_are_accepted(): void
    {
        $id = $this->stageRows([
            $this->row(['full_name' => 'Faadumo Axmed', 'phone' => '0615000011', 'gender' => 'F', 'category' => 'general cleaning']),
            $this->row(['full_name' => 'Maxamed Cali', 'phone' => '0615000012', 'gender' => 'M', 'category' => ' HOME  TEAM ']),
        ]);
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 2);

        $this->assertSame(['female', 'male'], Employee::query()->orderBy('id')->get()->map(fn ($e) => $e->gender->value)->all());
    }

    public function test_an_unknown_department_or_position_is_reported_not_silently_dropped(): void
    {
        $header = [...self::HEADER, 'department', 'position'];
        $id = $this->stageRows([[...$this->row(), 'Nowhere', ''], [...$this->row(['phone' => '0615000021']), '', 'Nobody']], $header);

        $this->assertSame(['DEPARTMENT_UNKNOWN', 'POSITION_UNKNOWN'], array_column($this->rows($id), 'code'));
    }

    public function test_hundreds_of_registrations_import_in_bounded_batches_with_unique_employee_numbers(): void
    {
        $rows = array_map(fn (int $i): array => $this->row(['full_name' => "Bulk Person {$i}", 'phone' => '6150'.str_pad((string) $i, 5, '0', STR_PAD_LEFT)]), range(1, 230));
        $id = $this->stageRows($rows);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 230);

        $this->assertSame(230, Employee::query()->count());
        $this->assertSame(230, Employee::query()->distinct()->count('employee_number'));
        $this->assertSame(230, DB::table('employee_status_histories')->count());
    }
}
