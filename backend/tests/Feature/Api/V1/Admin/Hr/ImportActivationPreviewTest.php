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

    public function test_another_record_for_the_same_person_is_flagged_for_review(): void
    {
        $one = $this->person('waiting', 'Same Person', '615000001');
        $twin = $this->person('applicant', 'Same Person', '615999999');

        $id = $this->stage([$this->plan($one)]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('DUPLICATE_PERSON_RECORD', $row['code']);
        $this->assertSame([$twin->employee_number], $row['meta']['employee_numbers']);
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
