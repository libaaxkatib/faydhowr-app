<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Hr\WorkbookPlan\WorkbookPlanExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * Phase E: read-only activation preview of the approved employee plan. It verifies identities, duplicates
 * and statuses and reports the transition path per employee - and NEVER activates anyone.
 */
class ImportActivationPreviewTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    private const HEADER = ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date'];

    private string $token;

    private int $sourceRow = 1;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->importSuperAdmin();
        $this->makeCompany('Registered Co');
    }

    private function person(string $status, string $name, string $phone): Employee
    {
        $employee = $this->makeEmployee($status, 'permanent', $phone);
        $employee->update(['full_name' => $name]);

        return $employee->fresh();
    }

    /** @return list<string> a plan row for the employee */
    private function plan(Employee $employee, array $overrides = []): array
    {
        $row = [
            'source_row' => (string) $this->sourceRow++,
            'employee_id' => (string) $employee->id,
            'employee_number' => $employee->employee_number,
            'name_key' => WorkbookPlanExecutor::nameKey($employee->full_name),
            'expected_status' => $employee->status->value,
            'company' => 'Registered Co',
            'salary' => '300',
            'start_date' => '2026-10-01',
        ];

        return array_values(array_replace($row, $overrides));
    }

    private function stage(array $rows): int
    {
        return $this->stageImport($this->token, 'activation_preview', $this->csvUpload(self::HEADER, $rows));
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $id): array
    {
        return $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->assertOk()->json('data');
    }

    public function test_each_employee_gets_its_transition_path_and_reason(): void
    {
        $waiting = $this->person('waiting', 'Waiting Person', '615000001');
        $applicant = $this->person('applicant', 'Applicant Person', '615000002');
        $inactive = $this->person('inactive', 'Inactive Person', '615000003');

        $id = $this->stage([$this->plan($waiting), $this->plan($applicant), $this->plan($inactive)]);
        $rows = $this->rows($id);

        $this->assertSame(['valid', 'valid', 'valid'], array_column($rows, 'status'));
        $paths = array_map(fn (array $r): string => $r['meta']['report']['proposed_path'], $rows);
        $this->assertSame(['normal_step', 'super_admin_override_required', 'rehire'], $paths);
        $this->assertSame(['no', 'yes', 'no'], array_map(fn (array $r): string => $r['meta']['report']['requires_super_admin_override'], $rows));
        $this->assertStringContainsString('normal lifecycle step', $rows[0]['message']);
        $this->assertStringContainsString('Super Admin override', $rows[1]['message']);
        $this->assertStringContainsString('Rehire', $rows[2]['message']);

        $summary = $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->assertOk();
        $summary->assertJsonPath('data.activation_paths.normal_step', 1)
            ->assertJsonPath('data.activation_paths.super_admin_override_required', 1)
            ->assertJsonPath('data.activation_paths.rehire', 1);
    }

    public function test_the_preview_never_activates_or_changes_anything_even_if_forced(): void
    {
        $waiting = $this->person('waiting', 'Waiting Person', '615000001');
        $applicant = $this->person('applicant', 'Applicant Person', '615000002');
        $id = $this->stage([$this->plan($waiting), $this->plan($applicant)]);
        $snapshot = fn (): array => [Employee::query()->orderBy('id')->get()->toJson(), DB::table('employee_status_histories')->count(), DB::table('employee_work_assignments')->count(), ClientCompany::query()->count()];
        $before = $snapshot();

        // Confirming is refused...
        $this->confirmImport($this->token, $id)->assertStatus(422)->assertJsonPath('error_code', 'PREVIEW_ONLY');
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertStatus(409);

        // ...even if the batch were forced into processing, the handler itself refuses.
        ImportBatch::query()->whereKey($id)->update(['status' => 'processing']);
        $this->as($this->token)->postJson(self::IMPORTS."/{$id}/process")->assertOk();
        $this->assertSame(2, ImportRow::query()->where('import_batch_id', $id)->where('code', 'PREVIEW_ONLY')->where('status', 'failed')->count());

        $this->assertSame($before, $snapshot());
        $this->assertSame('waiting', $waiting->fresh()->status->value);
        $this->assertSame('applicant', $applicant->fresh()->status->value);
    }

    public function test_identity_mismatches_missing_and_archived_employees_are_reported_as_invalid(): void
    {
        $good = $this->person('waiting', 'Good Person', '615000001');
        $wrongNumber = $this->person('waiting', 'Wrong Number', '615000002');
        $wrongName = $this->person('waiting', 'Wrong Name', '615000003');
        $archived = $this->person('waiting', 'Archived Person', '615000004');
        $archived->delete();
        $drifted = $this->person('applicant', 'Drifted Person', '615000005');

        $id = $this->stage([
            $this->plan($good),
            $this->plan($wrongNumber, ['employee_number' => 'EMP-000000']),
            $this->plan($wrongName, ['name_key' => 'somebodyelse']),
            $this->plan($archived),
            [(string) $this->sourceRow++, '999999', 'EMP-999999', 'ghost', 'waiting', 'Registered Co', '300', '2026-10-01'],
            $this->plan($drifted, ['expected_status' => 'waiting']),
        ]);

        $this->assertSame(
            ['valid', 'invalid', 'invalid', 'invalid', 'invalid', 'invalid'],
            array_column($this->rows($id), 'status'),
        );
        $this->assertSame(
            [null, 'IDENTITY_MISMATCH', 'IDENTITY_MISMATCH', 'EMPLOYEE_ARCHIVED', 'EMPLOYEE_NOT_FOUND', 'STATUS_CHANGED_SINCE_PLAN'],
            array_column($this->rows($id), 'code'),
        );
    }

    public function test_an_already_active_employee_and_a_repeat_in_the_file_are_duplicates_not_actions(): void
    {
        $active = $this->person('active', 'Active Person', '615000001');
        $waiting = $this->person('waiting', 'Waiting Person', '615000002');

        $id = $this->stage([$this->plan($active), $this->plan($waiting), $this->plan($waiting)]);
        $rows = $this->rows($id);

        $this->assertSame(['duplicate', 'valid', 'duplicate'], array_column($rows, 'status'));
        $this->assertSame(['ALREADY_ACTIVE', null, 'DUPLICATE_IN_FILE'], array_column($rows, 'code'));
        $this->assertSame('already_active', $rows[0]['meta']['report']['proposed_path']);
    }

    public function test_every_preview_row_that_found_an_employee_links_to_it_even_when_it_has_a_lookalike_or_is_invalid(): void
    {
        $twinA = $this->person('waiting', 'Twin Person', '615000001');
        $this->person('applicant', 'Twin Person', '615000002');
        $mismatch = $this->person('waiting', 'Mismatch Person', '615000003');
        $archived = $this->person('waiting', 'Archived Person', '615000004');
        $archived->delete();
        $drifted = $this->person('applicant', 'Drifted Person', '615000005');

        $id = $this->stage([
            $this->plan($twinA),
            $this->plan($mismatch, ['employee_number' => 'EMP-000000']),
            $this->plan($archived),
            $this->plan($drifted, ['expected_status' => 'waiting']),
        ]);
        $rows = $this->rows($id);

        $this->assertSame(['valid', 'invalid', 'invalid', 'invalid'], array_column($rows, 'status'));
        $this->assertSame([$twinA->id, $mismatch->id, $archived->id, $drifted->id], array_column($rows, 'record_id'));
        $this->assertSame(['Employee', 'Employee', 'Employee', 'Employee'], array_column($rows, 'record_type'));
    }

    public function test_a_look_alike_record_does_not_exclude_an_employee_whose_number_and_name_match(): void
    {
        $one = $this->person('waiting', 'Same Person', '615000001');
        $twin = $this->person('applicant', 'Same Person', '615999999');
        $archivedTwin = $this->person('inactive', 'Same Person', '615888888');
        $archivedTwin->delete();

        $id = $this->stage([$this->plan($one)]);
        $row = $this->rows($id)[0];

        // Approved rule: employee number AND name match, so the employee is activation-ready.
        $this->assertSame('valid', $row['status']);
        $this->assertNull($row['code']);
        $this->assertSame('yes', $row['meta']['report']['activation_ready']);
        $this->assertSame('', $row['meta']['report']['exclusion_reason']);
        $this->assertContains('LOOKALIKE_RECORD_NOTED', $row['meta']['warnings']);

        // The look-alikes are noted for information only, without names or phone numbers.
        $this->assertEqualsCanonicalizing([$twin->employee_number, $archivedTwin->employee_number], $row['meta']['employee_numbers']);
        $note = $row['meta']['report']['lookalike_note'];
        $this->assertStringContainsString('INCLUDED', $note);
        $this->assertStringContainsString($archivedTwin->employee_number.' (inactive, archived): same full name, different phone', $note);
        $this->assertStringContainsString($twin->employee_number.' (applicant): same full name, different phone', $note);
        $this->assertStringContainsString('registration phone is untouched', $note);
        $this->assertStringNotContainsString('Same Person', $note);
        $this->assertStringNotContainsString('615', $note);
    }

    public function test_look_alikes_are_counted_as_notes_and_never_as_exclusions(): void
    {
        $ready = $this->person('waiting', 'Clear Person', '615000001');
        $withTwin = $this->person('applicant', 'Doubtful Person', '615000003');
        $this->person('waiting', 'Doubtful Person', '615777777');

        $id = $this->stage([$this->plan($ready), $this->plan($withTwin)]);
        $summary = $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->assertOk()->json('data');

        $this->assertSame(2, $summary['activation_ready']);
        $this->assertSame(['normal_step' => 1, 'super_admin_override_required' => 1], $summary['activation_ready_by_path']);
        $this->assertSame([], $summary['activation_excluded']);
        $this->assertSame(0, $summary['activation_excluded_total']);
        $this->assertSame(1, $summary['activation_lookalikes_noted']);
        $this->assertSame(0, $summary['counts']['review']);

        $readyReport = $this->as($this->token)->get(self::IMPORTS."/{$id}/report?status=valid")->assertOk()->streamedContent();
        $this->assertStringContainsString($withTwin->employee_number, $readyReport);
        $this->assertStringContainsString('lookalike_note', $readyReport);
    }

    public function test_a_look_alike_is_never_matched_merged_created_or_overwritten_and_phones_are_preserved(): void
    {
        $employee = $this->person('applicant', 'Doubtful Person', '615000003');
        $twin = $this->person('waiting', 'Doubtful Person', '615777777');
        $snapshot = fn (): array => [
            Employee::withTrashed()->orderBy('id')->get()->toJson(),
            Employee::withTrashed()->orderBy('id')->pluck('phone')->all(),
            EmployeeStatusHistory::query()->count(),
            DB::table('employee_work_assignments')->count(),
        ];
        $before = $snapshot();

        $id = $this->stage([$this->plan($employee)]);
        $this->confirmImport($this->token, $id)->assertStatus(422)->assertJsonPath('error_code', 'PREVIEW_ONLY');

        $this->assertSame($before, $snapshot(), 'Nothing is created, merged, archived, activated or rewritten; every phone is unchanged.');
        $this->assertSame('615000003', $employee->fresh()->phone);
        $this->assertSame('615777777', $twin->fresh()->phone);
        $this->assertSame('applicant', $employee->fresh()->status->value);
    }

    public function test_an_employee_number_that_belongs_to_a_different_record_is_flagged_and_nobody_is_selected(): void
    {
        $a = $this->person('waiting', 'Person Alpha', '615000001');
        $b = $this->person('waiting', 'Person Beta', '615000002');

        // The plan row names employee A's id but gives employee B's number: a genuine employee-number conflict.
        $id = $this->stage([$this->plan($a, ['employee_number' => $b->employee_number]), $this->plan($b)]);
        $rows = $this->rows($id);

        $this->assertSame(['invalid', 'valid'], array_column($rows, 'status'));
        $this->assertSame(['EMPLOYEE_NUMBER_CONFLICT', null], array_column($rows, 'code'));
        $this->assertSame($a->id, $rows[0]['record_id'], 'The conflict stays on the row that carried it.');
        $this->assertSame('no', $rows[0]['meta']['report']['activation_ready']);
        $this->assertStringContainsString('Nobody was selected', $rows[0]['message']);
        $this->assertSame('waiting', $a->fresh()->status->value);
        $this->assertSame('waiting', $b->fresh()->status->value);
    }

    public function test_a_matching_number_with_a_different_name_is_flagged_not_assumed_to_be_the_same_person(): void
    {
        $employee = $this->person('waiting', 'Real Name', '615000001');

        $id = $this->stage([$this->plan($employee, ['name_key' => 'somethingelse'])]);
        $row = $this->rows($id)[0];

        $this->assertSame('invalid', $row['status']);
        $this->assertSame('IDENTITY_MISMATCH', $row['code']);
        $this->assertStringContainsString('the name differs', $row['message']);
    }

    public function test_every_planned_row_is_accounted_for_exactly_once_with_no_duplicates_or_gaps(): void
    {
        $waiting = $this->person('waiting', 'Waiting One', '615000001');
        $applicant = $this->person('applicant', 'Applicant One', '615000002');
        $inactive = $this->person('inactive', 'Inactive One', '615000003');
        $twinned = $this->person('applicant', 'Twinned Person', '615000004');
        $this->person('waiting', 'Twinned Person', '615000099');
        $active = $this->person('active', 'Already Active', '615000005');
        $conflict = $this->person('waiting', 'Conflict Person', '615000006');

        $rowsIn = [
            $this->plan($waiting), $this->plan($applicant), $this->plan($inactive), $this->plan($twinned), $this->plan($active),
            $this->plan($conflict, ['employee_number' => 'EMP-999999']),
            $this->plan($waiting), // the same employee listed twice
        ];
        $id = $this->stage($rowsIn);
        $rows = $this->rows($id);
        $batch = $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->json('data');

        $this->assertCount(count($rowsIn), $rows, 'Every row of the file has exactly one result.');
        $this->assertSame(range(2, count($rowsIn) + 1), array_column($rows, 'row_number'), 'No gaps and no repeats in the sheet row numbers.');
        $this->assertSame(count($rowsIn), $batch['counts']['total']);
        $this->assertSame(count($rowsIn), $batch['counts']['valid'] + $batch['counts']['invalid'] + $batch['counts']['duplicate'] + $batch['counts']['review']);

        $this->assertSame(['valid', 'valid', 'valid', 'valid', 'duplicate', 'invalid', 'duplicate'], array_column($rows, 'status'));
        $this->assertSame(4, $batch['activation_ready'], 'Ready = waiting, applicant, inactive and the look-alike employee.');
        $this->assertSame(['IDENTITY_MISMATCH' => 1], $batch['activation_excluded']);
        $this->assertSame(1, $batch['activation_lookalikes_noted']);
        $this->assertSame('DUPLICATE_IN_FILE', $rows[6]['code'], 'A repeated employee is not counted twice.');
    }

    public function test_missing_pay_data_and_unregistered_companies_are_reported_not_invented(): void
    {
        $noSalary = $this->person('waiting', 'No Salary', '615000001');
        $badDate = $this->person('waiting', 'Bad Date', '615000002');
        $newCompany = $this->person('waiting', 'New Company Person', '615000003');

        $id = $this->stage([
            $this->plan($noSalary, ['salary' => '']),
            $this->plan($badDate, ['start_date' => 'soon']),
            $this->plan($newCompany, ['company' => 'Not Yet Registered Co']),
        ]);
        $rows = $this->rows($id);

        $this->assertSame(['review', 'review', 'valid'], array_column($rows, 'status'));
        $this->assertSame(['SALARY_OR_START_MISSING', 'SALARY_OR_START_MISSING', null], array_column($rows, 'code'));
        $this->assertSame('no', $rows[2]['meta']['report']['company_registered']);
        $this->assertContains('COMPANY_NOT_REGISTERED_YET', $rows[2]['meta']['warnings']);

        $summary = $this->as($this->token)->getJson(self::IMPORTS."/{$id}")->json('data.activation_warnings');
        $this->assertSame(1, $summary['COMPANY_NOT_REGISTERED_YET']);
        $this->assertSame(2, $summary['SALARY_OR_START_MISSING']);
    }

    public function test_historical_completion_evidence_is_counted_and_never_invented(): void
    {
        $applicant = $this->person('applicant', 'Evidence Person', '615000001');
        $none = $this->person('applicant', 'No Evidence', '615000002');
        foreach (['practical', 'training', 'uniform'] as $stage) {
            DB::table('employee_historical_completions')->insert(['employee_id' => $applicant->id, 'stage' => $stage, 'completed_at' => now(), 'source' => 'test', 'created_at' => now(), 'updated_at' => now()]);
        }

        $id = $this->stage([$this->plan($applicant), $this->plan($none)]);
        $rows = $this->rows($id);

        $this->assertSame('3', $rows[0]['meta']['report']['historical_completion_stages']);
        $this->assertSame('0', $rows[1]['meta']['report']['historical_completion_stages']);
        $this->assertSame(3, DB::table('employee_historical_completions')->count(), 'The preview adds no completion records.');
    }

    public function test_the_report_lists_the_per_employee_path_with_reasons(): void
    {
        $waiting = $this->person('waiting', 'Waiting Person', '615000001');
        $applicant = $this->person('applicant', 'Applicant Person', '615000002');
        $id = $this->stage([$this->plan($waiting), $this->plan($applicant)]);

        $csv = $this->as($this->token)->get(self::IMPORTS."/{$id}/report")->assertOk()->streamedContent();
        $lines = array_values(array_filter(explode("\n", str_replace("\r", '', ltrim($csv, "\xEF\xBB\xBF")))));

        $this->assertStringContainsString('proposed_path,requires_super_admin_override,path_explanation', $lines[0]);
        $this->assertStringContainsString($waiting->employee_number, $csv);
        $this->assertStringContainsString('super_admin_override_required,yes', $csv);
        $this->assertStringNotContainsString('Waiting Person', $csv);
        $this->assertStringNotContainsString('615000001', $csv);
    }

    public function test_the_same_preview_file_can_be_run_again_after_the_data_changes(): void
    {
        $waiting = $this->person('waiting', 'Waiting Person', '615000001');
        $rows = [$this->plan($waiting)];

        $first = $this->stage($rows);
        $waiting->update(['status' => 'active']);
        $second = $this->stage($rows);

        $this->assertNotSame($first, $second);
        $this->assertSame('ALREADY_ACTIVE', $this->rows($second)[0]['code']);
    }

    public function test_the_preview_follows_the_guard_for_other_statuses(): void
    {
        $recruitment = $this->person('recruitment', 'In Recruitment', '615000001');
        $practical = $this->person('practical', 'In Practical', '615000002');
        $approved = $this->person('approved', 'Was Approved', '615000003');

        $id = $this->stage([$this->plan($recruitment), $this->plan($practical), $this->plan($approved)]);
        $paths = array_map(fn (array $r): string => $r['meta']['report']['proposed_path'], $this->rows($id));

        $this->assertSame(['super_admin_override_required', 'super_admin_override_required', 'normal_step'], $paths);
        $this->assertSame(0, EmployeeStatusHistory::query()->count());
    }
}
