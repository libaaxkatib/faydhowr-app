<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\ImportRow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\Support\ImportFixtures;
use Tests\TestCase;

/**
 * Phase D: waiting-list import. Only an EXISTING employee, matched confidently on normalised name AND
 * registration phone, is moved to Waiting. Everything uncertain goes to manual review; nothing is created,
 * overwritten or guessed.
 */
class ImportWaitingListTest extends TestCase
{
    use HrFixtures;
    use ImportFixtures;
    use RefreshDatabase;

    private const HEADER = ['full_name', 'phone'];

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();

        [, $this->token] = $this->importSuperAdmin();
    }

    private function person(string $status, string $name, string $phone, array $extra = []): Employee
    {
        $employee = $this->makeEmployee($status, 'permanent', $phone);
        $employee->update(['full_name' => $name, ...$extra]);

        return $employee->fresh();
    }

    private function stageRows(array $rows): int
    {
        return $this->stageImport($this->token, 'waiting_list', $this->csvUpload(self::HEADER, $rows));
    }

    /** @return list<array<string, mixed>> */
    private function rows(int $id): array
    {
        return $this->as($this->token)->getJson(self::IMPORTS."/{$id}/rows")->assertOk()->json('data');
    }

    public function test_a_confident_match_moves_the_existing_employee_to_waiting_and_records_it(): void
    {
        $employee = $this->person('applicant', 'Hodan Cabdi Nuur', '615123456', ['pipeline_stage' => 'need_practical', 'location' => 'Hodan', 'notes' => 'keep these notes']);
        $before = $employee->toArray();

        $id = $this->stageRows([['Hodan Cabdi Nuur', '0615123456']]);
        $row = $this->rows($id)[0];
        $this->assertSame('valid', $row['status']);
        $this->assertSame($employee->id, $row['record_id']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 1);

        $employee->refresh();
        $this->assertSame('waiting', $employee->status->value);
        $this->assertNotNull($employee->waiting_since);
        $this->assertNull($employee->pipeline_stage);

        // Nothing else about the employee changed.
        $after = $employee->toArray();
        foreach (['status', 'waiting_since', 'pipeline_stage', 'updated_at'] as $changed) {
            unset($before[$changed], $after[$changed]);
        }
        $this->assertSame($before, $after, 'No employee detail is overwritten by the waiting-list workflow.');

        $history = EmployeeStatusHistory::query()->where('employee_id', $employee->id)->firstOrFail();
        $this->assertSame('applicant', $history->from_status->value);
        $this->assertSame('waiting', $history->to_status->value);
        $this->assertSame('waiting_import', $history->source);
        $this->assertSame($id, $history->import_batch_id);
        $this->assertNotNull($history->changed_by);
        $this->assertNull($history->override_reason, 'An import never overrides the lifecycle.');

        $audit = DB::table('audit_logs')->where('entity_id', $employee->id)->where('description', 'like', "%waiting-list import #{$id}%")->first();
        $this->assertNotNull($audit);
        $this->assertSame('need_practical', json_decode($audit->metadata, true)['previous_pipeline_stage']);
    }

    public function test_names_are_normalised_for_matching_but_word_order_is_kept(): void
    {
        $this->person('applicant', 'Fartun Cali-Warsame', '615111111');
        $this->person('applicant', 'Ladan Cumar Faarax', '615222222');

        $id = $this->stageRows([['  FARTUN   cali warsame ', '+252 615 111 111'], ['Faarax Cumar Ladan', '0615222222']]);
        $rows = $this->rows($id);

        $this->assertSame('valid', $rows[0]['status']);
        $this->assertSame('review', $rows[1]['status']);
        $this->assertSame('NAME_CONFLICT', $rows[1]['code'], 'A reordered name is not a confident match.');
    }

    public function test_a_stored_phone_with_spaces_or_dashes_still_matches(): void
    {
        $this->person('applicant', 'Spaced Phone Person', '+252 615 333 444');

        $id = $this->stageRows([['Spaced Phone Person', '615333444']]);

        $this->assertSame('valid', $this->rows($id)[0]['status']);
    }

    public function test_phone_match_with_a_different_name_goes_to_manual_review_and_nothing_is_changed(): void
    {
        $employee = $this->person('applicant', 'Real Owner Name', '615123456');

        $id = $this->stageRows([['Somebody Else Entirely', '0615123456']]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('NAME_CONFLICT', $row['code']);
        $this->assertSame([$employee->employee_number], $row['meta']['employee_numbers']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 0)->assertJsonPath('data.counts.review', 1);
        $this->assertSame('applicant', $employee->fresh()->status->value);
    }

    public function test_a_similar_name_is_never_chosen_automatically(): void
    {
        $this->person('applicant', 'Hodan Cabdi Nuur', '615123456');

        // One letter different, same phone: close, but not equal.
        $id = $this->stageRows([['Hodan Cabdi Nur', '0615123456']]);

        $this->assertSame('NAME_CONFLICT', $this->rows($id)[0]['code']);
        $this->assertSame('applicant', Employee::query()->firstOrFail()->status->value);
    }

    public function test_name_match_with_a_different_phone_goes_to_manual_review(): void
    {
        $employee = $this->person('applicant', 'Hodan Cabdi Nuur', '615123456');

        $id = $this->stageRows([['Hodan Cabdi Nuur', '0615999999']]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('NAME_ONLY_MATCH', $row['code']);
        $this->assertSame([$employee->employee_number], $row['meta']['employee_numbers']);
        $this->assertSame('applicant', $employee->fresh()->status->value);
    }

    public function test_no_match_at_all_is_sent_to_review_not_rejected_and_nobody_is_created(): void
    {
        $id = $this->stageRows([['Unknown Person', '0615777777']]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('NO_MATCH', $row['code']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 0)->assertJsonPath('data.counts.invalid', 0);
        $this->assertSame(0, Employee::query()->count(), 'The waiting-list workflow never creates an employee.');
    }

    public function test_two_employees_sharing_the_registration_phone_are_never_guessed_between(): void
    {
        $a = $this->person('applicant', 'Twin One', '615123456');
        $b = $this->person('applicant', 'Twin One', '0615123456');

        $id = $this->stageRows([['Twin One', '615123456']]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('MULTIPLE_PHONE_MATCHES', $row['code']);
        $this->assertEqualsCanonicalizing([$a->employee_number, $b->employee_number], $row['meta']['employee_numbers']);
    }

    public function test_a_number_that_is_only_an_alternate_phone_is_not_a_registration_match(): void
    {
        $employee = $this->person('applicant', 'Has Alternate', '615000000', ['alternate_phone' => '615123456']);

        $id = $this->stageRows([['Has Alternate', '0615123456']]);
        $row = $this->rows($id)[0];

        $this->assertSame('review', $row['status']);
        $this->assertSame('ALTERNATE_PHONE_ONLY_MATCH', $row['code']);
        $this->assertSame('applicant', $employee->fresh()->status->value);
    }

    public function test_an_archived_employee_goes_to_manual_review(): void
    {
        $archived = $this->person('applicant', 'Archived Person', '615123456');
        $archived->delete();

        $id = $this->stageRows([['Archived Person', '0615123456']]);

        $this->assertSame('ARCHIVED_MATCH', $this->rows($id)[0]['code']);
        $this->assertSame('applicant', Employee::withTrashed()->firstOrFail()->status->value);
    }

    public function test_an_employee_already_waiting_is_skipped_unchanged(): void
    {
        $waiting = $this->person('waiting', 'Already Waiting', '615123456', ['waiting_since' => now()->subDays(40)]);
        $since = $waiting->waiting_since->toDateTimeString();

        $id = $this->stageRows([['Already Waiting', '0615123456']]);
        $this->assertSame('ALREADY_WAITING', $this->rows($id)[0]['code']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 0);
        $this->assertSame($since, $waiting->fresh()->waiting_since->toDateTimeString(), 'The waiting clock is not reset.');
        $this->assertSame(0, EmployeeStatusHistory::query()->count());
    }

    public function test_active_and_inactive_employees_cannot_be_moved_to_waiting_by_an_import(): void
    {
        $active = $this->person('active', 'Active Person', '615111000');
        $inactive = $this->person('inactive', 'Inactive Person', '615222000');

        $id = $this->stageRows([['Active Person', '615111000'], ['Inactive Person', '615222000']]);
        $rows = $this->rows($id);

        $this->assertSame(['invalid', 'invalid'], array_column($rows, 'status'));
        $this->assertSame(['TRANSITION_NOT_ALLOWED', 'TRANSITION_NOT_ALLOWED'], array_column($rows, 'code'));

        $this->runImport($this->token, $id);
        $this->assertSame('active', $active->fresh()->status->value);
        $this->assertSame('inactive', $inactive->fresh()->status->value);
    }

    public function test_recruitment_and_practical_employees_can_be_moved_to_waiting(): void
    {
        $this->person('recruitment', 'In Recruitment', '615111000');
        $this->person('practical', 'In Practical', '615222000');

        $id = $this->stageRows([['In Recruitment', '615111000'], ['In Practical', '615222000']]);
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 2);

        $this->assertSame(2, Employee::query()->where('status', 'waiting')->count());
    }

    public function test_the_same_phone_twice_in_the_file_is_used_once(): void
    {
        $this->person('applicant', 'Only Once', '615123456');

        $id = $this->stageRows([['Only Once', '615123456'], ['Only Once', '0615123456']]);
        $rows = $this->rows($id);

        $this->assertSame(['valid', 'duplicate'], array_column($rows, 'status'));
        $this->runImport($this->token, $id)->assertJsonPath('data.counts.imported', 1);
        $this->assertSame(1, EmployeeStatusHistory::query()->count());
    }

    public function test_a_match_that_changed_after_the_preview_is_not_applied(): void
    {
        $employee = $this->person('applicant', 'Changing Person', '615123456');
        $id = $this->stageRows([['Changing Person', '615123456']]);

        // Before confirmation someone corrects the employee's name.
        $employee->update(['full_name' => 'Corrected Spelling Person']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.skipped', 1)->assertJsonPath('data.counts.imported', 0);
        $this->assertSame('applicant', $employee->fresh()->status->value);
        $this->assertSame('MATCH_CHANGED', ImportRow::query()->where('import_batch_id', $id)->value('code'));
    }

    public function test_an_employee_activated_after_the_preview_is_not_moved_back_to_waiting(): void
    {
        $employee = $this->person('applicant', 'Moved On Person', '615123456');
        $id = $this->stageRows([['Moved On Person', '615123456']]);
        $employee->update(['status' => 'active']);

        $this->runImport($this->token, $id)->assertJsonPath('data.counts.skipped', 1);
        $this->assertSame('active', $employee->fresh()->status->value);
    }

    public function test_review_rows_are_reported_in_the_row_level_report_without_names(): void
    {
        $this->person('applicant', 'Real Owner Name', '615123456');
        $id = $this->stageRows([['Somebody Else Entirely', '0615123456'], ['Unknown Person', '0615777777']]);
        $this->runImport($this->token, $id);

        $csv = $this->as($this->token)->get(self::IMPORTS."/{$id}/report?status=review")->assertOk()->streamedContent();

        $this->assertStringContainsString('NAME_CONFLICT', $csv);
        $this->assertStringContainsString('NO_MATCH', $csv);
        $this->assertStringNotContainsString('Somebody Else', $csv);
        $this->assertStringNotContainsString('0615123456', $csv);
    }

    public function test_invalid_names_and_phones_are_invalid_not_review(): void
    {
        $id = $this->stageRows([['', '0615123456'], ['No Phone', ''], ['Short Phone', '12345']]);

        $this->assertSame(['NAME_REQUIRED', 'PHONE_INVALID', 'PHONE_INVALID'], array_column($this->rows($id), 'code'));
    }

    public function test_an_unconfirmed_waiting_import_changes_nothing(): void
    {
        $employee = $this->person('applicant', 'Not Yet', '615123456');

        $this->stageRows([['Not Yet', '615123456']]);

        $this->assertSame('applicant', $employee->fresh()->status->value);
        $this->assertSame(0, EmployeeStatusHistory::query()->count());
    }
}
