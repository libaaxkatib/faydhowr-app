<?php

namespace Tests\Feature\Hr;

use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeSeparation;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Support\Hr\WorkbookPlan\WorkbookPlanExecutor;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Safe local fixtures for the approved Part Two handling rules (nothing here touches real data): the people in the plan are EXISTING records,
 * applicants are made Active directly by the audited Super Admin override without any invented history, an approved rehire keeps the
 * separation history, a person with two companies keeps two separate assignments (needs the Super Admin multiple-company switch), and
 * rows that are not in the plan - possible duplicates included - are never touched.
 */
class PartTwoHandlingTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const OVERRIDE = 'Part Two: owner approved direct activation of the registered applicants from the approved workbook.';

    private const LABEL = 'EMPLOYEE AND COMPANY PART TWO.xlsx';

    private string $dir;

    private Admin $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'p2_'.uniqid();
        mkdir($this->dir);
        $this->admin = Admin::factory()->superAdmin()->create();
    }

    protected function tearDown(): void
    {
        array_map('unlink', glob($this->dir.DIRECTORY_SEPARATOR.'*') ?: []);
        @rmdir($this->dir);
        parent::tearDown();
    }

    private function csv(string $name, array $header, array $rows): string
    {
        $path = $this->dir.DIRECTORY_SEPARATOR.$name;
        $h = fopen($path, 'w');
        fputcsv($h, $header, ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($h, $row, ',', '"', '\\');
        }
        fclose($h);

        return $path;
    }

    private function applyPlan(string $employeesFile, bool $commit, ?string $report = null, ?string $companies = null): void
    {
        $args = ['--employees' => $employeesFile, '--override-reason' => self::OVERRIDE, '--source-label' => self::LABEL, '--joining-date' => '2026-10-01', '--note' => 'Approved as working in the Part Two workbook'];

        if ($companies !== null) {
            $args['--companies'] = $companies;
        }

        if ($commit) {
            $args += ['--commit' => true, '--admin-email' => $this->admin->email];
        }

        if ($report !== null) {
            $args['--report'] = $report;
        }

        $this->artisan('hr:apply-workbook-plan', $args)->run();
    }

    /** @return list<array<string, string>> step, reference (company name or "employee <id>"), outcome, code, message */
    private function readReport(string $path): array
    {
        $out = [];
        $h = fopen($path, 'r');
        $header = fgetcsv($h, 0, ',', '"', '\\');

        while (($r = fgetcsv($h, 0, ',', '"', '\\')) !== false) {
            $out[] = array_combine($header, $r);
        }

        fclose($h);

        return $out;
    }

    /** @param list<array<string, string>> $rows */
    private function outcome(array $rows, string $step, string $reference): array
    {
        $hit = array_values(array_filter($rows, fn (array $r): bool => $r['step'] === $step && str_starts_with($r['reference'], $reference)));
        $this->assertNotEmpty($hit, "no {$step} result for {$reference}");

        return $hit[0];
    }

    private function planRow(int $sourceRow, Employee $e, string $status, string $company, string $salary): array
    {
        return [$sourceRow, $e->id, $e->employee_number, WorkbookPlanExecutor::nameKey($e->full_name), $status, $company, $salary, '2026-10-01'];
    }

    private function snapshot(Employee $e): array
    {
        return $e->fresh()->only(['status', 'pipeline_stage', 'full_name', 'phone', 'employee_number', 'registration_origin']);
    }

    public function test_the_part_two_rules_on_safe_local_fixtures(): void
    {
        $header = ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date'];
        $companyA = $this->makeCompany('MOGADISHUSPECIALIST');
        $this->makeCompany('MACHADTARTIIL');
        $this->makeCompany('ILEYS ACADEMY');
        $this->makeCompany('HORSEED HOSPITAL');

        // Existing waiting person and an existing applicant who has a stage but NO guarantor/contract/uniform/training/practice record.
        $waiting = $this->makeEmployee('waiting');
        $applicant = $this->makeEmployee('applicant');
        $applicant->update(['pipeline_stage' => 'need_training']);
        // A same-name record that is NOT in the plan (possible duplicate): must never be touched.
        $twin = $this->makeEmployee('inactive');
        $twin->update(['full_name' => $applicant->full_name]);
        // A person the owner will enter manually later (not in the plan).
        $manual = $this->makeEmployee('applicant');
        // The approved rehire: separated, one separation record, no current assignment.
        $rehire = $this->makeEmployee('active');
        $this->withToken($this->admin->createToken('t')->plainTextToken)->patchJson("/api/v1/admin/hr/employees/{$rehire->id}/separate", ['reason' => 'resigned', 'separation_date' => '2026-09-28', 'rehire_eligible' => true])->assertOk();
        // Row 58: already works at MOGADISHUSPECIALIST for 250.
        $two = $this->makeEmployee('active');
        $first = $this->makeAssignment($two, $companyA, null, 250, '2026-10-01');

        foreach ([$waiting, $applicant, $twin, $manual, $rehire, $two] as $e) {
            $this->assertSame('existing', $e->fresh()->registration_origin->value, 'everybody in these fixtures is an existing record');
        }

        $plan = $this->csv('employees.csv', $header, [
            $this->planRow(2, $waiting, 'waiting', 'HORSEED HOSPITAL', '200'),
            $this->planRow(3, $applicant, 'applicant', 'HORSEED HOSPITAL', '150'),
            $this->planRow(53, $rehire, 'inactive', 'ILEYS ACADEMY', '130'),
            $this->planRow(58, $two, 'active', 'MACHADTARTIIL', '120'),
        ]);
        $before = [ClientCompany::query()->count(), EmployeeWorkAssignment::query()->count(), Employee::query()->count(), EmployeeStatusHistory::query()->count(), $this->snapshot($applicant)];
        $untouched = [$this->snapshot($twin), $this->snapshot($manual)];

        // 1. A dry run writes nothing.
        $this->applyPlan($plan, false);
        $this->assertSame($before, [ClientCompany::query()->count(), EmployeeWorkAssignment::query()->count(), Employee::query()->count(), EmployeeStatusHistory::query()->count(), $this->snapshot($applicant)]);

        // 2. Commit with the multiple-company switch OFF: everyone is done except the second company of row 58, which is refused.
        $report = $this->dir.DIRECTORY_SEPARATOR.'r1.csv';
        $this->applyPlan($plan, true, $report);
        $r = $this->readReport($report);
        $this->assertSame('failed', $this->outcome($r, 'assignments', 'MACHADTARTIIL')['outcome'], 'a second company is refused while the Super Admin switch is off');
        $this->assertSame('assigned', $this->outcome($r, 'assignments', 'ILEYS ACADEMY')['outcome']);
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $two->id)->count());

        // 3. The applicant is Active directly through the recorded Super Admin override - and nothing was invented for them.
        $applicant->refresh();
        $this->assertSame('active', $applicant->status->value);
        $this->assertSame('existing', $applicant->registration_origin->value);
        $history = EmployeeStatusHistory::query()->where('employee_id', $applicant->id)->where('to_status', 'active')->firstOrFail();
        $this->assertSame(['applicant', 'override', self::OVERRIDE, $this->admin->id], [$history->from_status->value, $history->source, $history->override_reason, $history->changed_by]);
        $this->assertStringContainsString(self::LABEL.' row 3', (string) $history->note, 'the source row is on the history');
        $audit = AuditLog::query()->where('entity_type', Employee::class)->where('entity_id', $applicant->id)->get()->first(fn ($l) => ($l->metadata['override'] ?? false) === true);
        $this->assertNotNull($audit);
        $this->assertSame(self::OVERRIDE, $audit->metadata['override_reason']);
        foreach (['employee_guarantors', 'employee_contracts', 'employee_uniforms', 'training_batch_participants', 'employee_practical_assessments'] as $table) {
            $this->assertSame(0, DB::table($table)->where('employee_id', $applicant->id)->count(), "no {$table} record may be fabricated");
        }
        $this->assertSame('active', $waiting->fresh()->status->value);
        $this->assertNotSame('override', EmployeeStatusHistory::query()->where('employee_id', $waiting->id)->where('to_status', 'active')->value('source'), 'a Waiting person needs no override');

        // 4. The approved rehire reuses the record: the separation stays, one rehire is recorded, the person gets their assignment, nothing is duplicated.
        $rehire->refresh();
        $this->assertSame('active', $rehire->status->value);
        $this->assertSame(1, EmployeeSeparation::query()->where('employee_id', $rehire->id)->count());
        $this->assertSame(1, EmployeeStatusHistory::query()->where('employee_id', $rehire->id)->where('from_status', 'inactive')->where('to_status', 'active')->count());
        $this->assertSame(1, EmployeeWorkAssignment::query()->where('employee_id', $rehire->id)->count());
        $this->assertSame(Employee::query()->count(), $before[2], 'no employee was created');

        // 5. Row 58: after the Super Admin switches the policy on (audited), the SECOND assignment is created separately; the first is untouched.
        $this->withToken($this->admin->createToken('t2')->plainTextToken)->putJson('/api/v1/admin/hr/policies/multi_company_assignments', ['enabled' => true, 'reason' => 'One employee works a night and a day shift at two companies'])->assertOk();
        $before58 = (array) DB::table('employee_work_assignments')->where('id', $first->id)->first();
        $this->applyPlan($plan, true, $this->dir.DIRECTORY_SEPARATOR.'r2.csv');
        $assignments = EmployeeWorkAssignment::query()->where('employee_id', $two->id)->orderBy('id')->get();
        $this->assertCount(2, $assignments, 'two separate assignments, not one overwritten or combined');
        $this->assertSame($before58, (array) DB::table('employee_work_assignments')->where('id', $first->id)->first(), 'the existing MOGADISHUSPECIALIST assignment is unchanged (every column, including updated_at)');
        $this->assertSame(['250.00', '120.00'], $assignments->map(fn ($a) => (string) $a->salary_amount)->all());
        $this->assertNotSame($assignments[0]->client_company_id, $assignments[1]->client_company_id);
        $this->assertSame('MACHADTARTIIL', ClientCompany::query()->findOrFail($assignments[1]->client_company_id)->name);
        $this->assertSame(['USD', 'USD'], $assignments->pluck('salary_currency')->all());

        // 6. A second commit is idempotent.
        $counts = [EmployeeWorkAssignment::query()->count(), EmployeeStatusHistory::query()->count(), Employee::query()->count()];
        $this->applyPlan($plan, true);
        $this->assertSame($counts, [EmployeeWorkAssignment::query()->count(), EmployeeStatusHistory::query()->count(), Employee::query()->count()]);

        // 7. Rows that are not in the plan (possible duplicate, owner-manual) were never touched, and nobody was merged.
        $this->assertSame($untouched, [$this->snapshot($twin), $this->snapshot($manual)]);
        $this->assertSame(0, EmployeeWorkAssignment::query()->whereIn('employee_id', [$twin->id, $manual->id])->count());
    }

    public function test_a_wrong_identity_in_the_plan_changes_nothing_and_a_new_registration_is_never_activated_without_an_override(): void
    {
        $header = ['source_row', 'employee_id', 'employee_number', 'name_key', 'expected_status', 'company', 'salary', 'start_date'];
        $this->makeCompany('HORSEED HOSPITAL');
        $person = $this->makeEmployee('applicant');
        $newcomer = $this->makeEmployee('waiting');
        $newcomer->update(['registration_origin' => 'new']);

        $plan = $this->csv('e.csv', $header, [
            [2, $person->id, $person->employee_number, 'notthenameinproduction', 'applicant', 'HORSEED HOSPITAL', '100', '2026-10-01'],
            [3, $newcomer->id, $newcomer->employee_number, WorkbookPlanExecutor::nameKey($newcomer->full_name), 'waiting', 'HORSEED HOSPITAL', '100', '2026-10-01'],
        ]);
        $report = $this->dir.DIRECTORY_SEPARATOR.'r.csv';

        // No override reason supplied: the new registration stays Waiting (workflow incomplete) and the wrong identity is not assigned.
        $this->artisan('hr:apply-workbook-plan', ['--employees' => $plan, '--commit' => true, '--admin-email' => $this->admin->email, '--report' => $report])->run();
        $r = $this->readReport($report);

        $this->assertSame('REGISTRATION_WORKFLOW_INCOMPLETE', $this->outcome($r, 'status', "employee {$newcomer->id}")['code']);
        $this->assertSame('waiting', $newcomer->fresh()->status->value);
        $this->assertSame('IDENTITY_MISMATCH', $this->outcome($r, 'assignments', 'HORSEED HOSPITAL')['code']);
        $this->assertSame(0, EmployeeWorkAssignment::query()->count());
    }
}
