<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AssignmentAbsence;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeStatusHistory;
use App\Models\EmployeeWorkAssignment;
use App\Models\HrComplianceFinding;
use App\Models\PayrollItem;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Position;
use App\Models\TemporaryReplacement;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * HR compliance and accountability report. It finds people who work while a requirement the system ALREADY defines is not met (the registration form's
 * required fields, the five registration steps of a new registration, the mandatory assignment data, the replacement/succession rules), says whether the
 * finding is CONFIRMED or only NEEDS HR REVIEW, and names a responsible person ONLY when a record does. It changes nothing about employees, assignments
 * or payroll; the HR Manager records the follow-up (status + note), audited. Synthetic data only.
 */
class HrComplianceReportTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/compliance/findings';

    private ClientCompany $company;

    private Position $cleaner;

    private Admin $hrManager;

    private string $hr;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        $this->company = $this->makeCompany('Alpha Hospital');
        $this->cleaner = Position::query()->create(['name' => 'Cleaner']);
        $this->hrManager = Admin::factory()->create(['role' => AdminRole::HrManager, 'full_name' => 'Hodan HR Manager']);
        $this->grant($this->hrManager, ['hr.view', 'hr.reports.view', 'hr.manage']);
        $this->hr = $this->hrManager->createToken('t')->plainTextToken;
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @param  list<string>  $keys */
    private function grant(Admin $admin, array $keys): void
    {
        foreach ($keys as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function as(string $token)
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** A worker with a complete profile and a complete, consistent assignment: nothing to report. */
    private function sound(string $name = 'Sound Worker', ?ClientCompany $company = null, ?int $createdBy = null): array
    {
        $e = $this->makeEmployee('active', 'permanent');
        $e->forceFill(['full_name' => $name, 'location' => 'Mogadishu', 'gender' => 'female'])->save();
        $a = $this->makeAssignment($e, $company ?? $this->company, null, 200, '2026-10-01');
        $a->update(['position_id' => $this->cleaner->id, 'shift' => 'day', 'created_by' => $createdBy]);

        return [$e, $a->refresh()];
    }

    /** @return list<array<string, mixed>> */
    private function findings(array $query = []): array
    {
        return $this->as($this->hr)->getJson(self::URL.'?'.http_build_query($query))->assertOk()->json('data.findings');
    }

    private function codes(array $findings, ?int $employeeId = null): array
    {
        $codes = array_column(array_filter($findings, fn (array $f): bool => $employeeId === null || $f['employee_id'] === $employeeId), 'code');
        sort($codes);

        return $codes;
    }

    private function find(array $findings, string $code, ?int $employeeId = null): array
    {
        foreach ($findings as $f) {
            if ($f['code'] === $code && ($employeeId === null || $f['employee_id'] === $employeeId)) {
                return $f;
            }
        }

        $this->fail("No {$code} finding: ".implode(', ', array_column($findings, 'code')));
    }

    // ------------------------------------------------------------------ nothing to report

    public function test_a_sound_worker_produces_no_finding_and_is_counted_as_reviewed(): void
    {
        $this->sound();

        $data = $this->as($this->hr)->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame([], $data['findings']);
        $this->assertSame(1, $data['summary']['active_employees_reviewed']);
        $this->assertSame(0, $data['summary']['total_findings']);
    }

    // ------------------------------------------------------------------ 1. incomplete profile

    public function test_missing_required_profile_fields_are_confirmed_for_a_new_registration_and_needs_review_for_an_imported_record(): void
    {
        [$e] = $this->sound('Profile Gap');
        DB::table('employees')->where('id', $e->id)->update(['location' => null, 'gender' => null]);

        $f = $this->find($this->findings(), 'PROFILE_FIELDS_MISSING', $e->id);

        $this->assertSame('profile', $f['category']);
        $this->assertSame('needs_review', $f['confidence'], 'an imported record predates the registration form: HR must review, nothing is accused');
        $this->assertStringContainsString('registered before these rules applied', $f['explanation']);

        // A NEW registration is bound by the form: the same gap is a confirmed finding.
        [$new] = $this->sound('New Registration Gap');
        DB::table('employees')->where('id', $new->id)->update(['location' => null, 'registration_origin' => 'new']);
        $newFinding = $this->find($this->findings(), 'PROFILE_FIELDS_MISSING', $new->id);
        $this->assertSame('confirmed', $newFinding['confidence']);
        $this->assertStringNotContainsString('registered before', $newFinding['explanation']);
        $this->assertStringContainsString('Location', $f['requirement']);
        $this->assertStringContainsString('Gender', $f['requirement']);
        $this->assertSame(['Location', 'Gender'], $f['details']['missing_fields']);
        $this->assertSame('Permanent', $f['employment_type']);
        $this->assertSame('Alpha Hospital', $f['company']);
        $this->assertSame('Cleaner', $f['position']);
        $this->assertSame('day', $f['shift']);
        $this->assertSame('2026-10-01', $f['appointed_on']);
        $this->assertSame('2026-11-05', $f['detected_on']);
        $this->assertSame('open', $f['status']);
        $this->assertNotEmpty($f['recommended_action']);
    }

    // ------------------------------------------------------------------ 2. unmet job requirements (the five defined steps)

    public function test_a_new_registration_working_without_the_registration_steps_is_a_confirmed_requirement_finding(): void
    {
        [$e] = $this->sound('New Hire');
        DB::table('employees')->where('id', $e->id)->update(['registration_origin' => 'new']);

        $f = $this->find($this->findings(), 'REGISTRATION_STEPS_INCOMPLETE', $e->id);

        $this->assertSame('job_requirements', $f['category']);
        $this->assertSame('confirmed', $f['confidence']);
        $this->assertSame('high', $f['severity']);
        $this->assertSame(['Guarantor verified', 'Contract signed', 'Uniform confirmed', 'Training completed', 'Practical approved'], $f['details']['missing_steps']);
    }

    public function test_an_existing_record_is_not_held_to_the_registration_steps_so_nothing_is_invented(): void
    {
        [$e] = $this->sound('Long Serving');

        $this->assertNotContains('REGISTRATION_STEPS_INCOMPLETE', $this->codes($this->findings(), $e->id));
        $this->assertNotContains('REGISTRATION_EXCEPTION_RECORDED', $this->codes($this->findings(), $e->id));
    }

    public function test_a_recorded_exception_is_needs_review_not_a_confirmed_violation_and_names_who_recorded_it(): void
    {
        [$e] = $this->sound('Exception Case');
        DB::table('employees')->where('id', $e->id)->update(['registration_origin' => 'new']);
        $recorder = Admin::factory()->create(['role' => AdminRole::SuperAdmin, 'full_name' => 'Sahra Super']);
        EmployeeStatusHistory::query()->create(['employee_id' => $e->id, 'from_status' => 'waiting', 'to_status' => 'active', 'changed_by' => $recorder->id, 'note' => 'x', 'override_reason' => 'Urgent client need approved by the owner', 'source' => 'override']);

        $f = $this->find($this->findings(), 'REGISTRATION_EXCEPTION_RECORDED', $e->id);

        $this->assertSame('needs_review', $f['confidence'], 'an authorised exception is for HR to confirm, not a violation');
        $this->assertSame('Urgent client need approved by the owner', $f['details']['exception_reason']);
        $this->assertSame('Sahra Super', $f['responsible']['name']);
        $this->assertSame('Changed the status to Active', $f['responsible']['action']);
        $this->assertNotContains('REGISTRATION_STEPS_INCOMPLETE', $this->codes($this->findings(), $e->id));
    }

    public function test_an_assignment_without_position_or_shift_is_needs_review_because_they_are_not_mandatory(): void
    {
        [$e, $a] = $this->sound('Bare Assignment');
        $a->update(['position_id' => null, 'shift' => null]);

        $f = $this->find($this->findings(), 'ASSIGNMENT_DETAILS_NOT_RECORDED', $e->id);

        $this->assertSame('needs_review', $f['confidence']);
        $this->assertSame('low', $f['severity']);
        $this->assertSame(['position', 'shift'], $f['details']['not_recorded']);
    }

    // ------------------------------------------------------------------ 3. incorrect HR workflow

    public function test_assignment_and_status_conflicts_are_confirmed_workflow_findings(): void
    {
        [$inactive] = $this->sound('Inactive Holder');
        DB::table('employees')->where('id', $inactive->id)->update(['status' => 'inactive']);
        [$noCompany, $noCompanyAssignment] = $this->sound('No Company');
        // PostgreSQL itself refuses an assignment with neither a company nor a workplace (a CHECK constraint), so this state can only be made on SQLite.
        $companyCheck = DB::getDriverName() !== 'pgsql';
        if ($companyCheck) {
            DB::table('employee_work_assignments')->where('id', $noCompanyAssignment->id)->update(['client_company_id' => null]);
        }
        [$noSalary, $noSalaryAssignment] = $this->sound('No Salary');
        DB::table('employee_work_assignments')->where('id', $noSalaryAssignment->id)->update(['salary_amount' => 0]);
        [$pastEnd, $pastEndAssignment] = $this->sound('Past End');
        DB::table('employee_work_assignments')->where('id', $pastEndAssignment->id)->update(['end_date' => '2026-10-20']);
        [$piped] = $this->sound('Still Piped');
        DB::table('employees')->where('id', $piped->id)->update(['pipeline_stage' => 'contract_pending']);

        $all = $this->findings();

        $this->assertContains('EMPLOYEE_NOT_ACTIVE', $this->codes($all, $inactive->id));
        $companyCheck ? $this->assertContains('ASSIGNMENT_COMPANY_MISSING', $this->codes($all, $noCompany->id)) : $this->assertNotContains('ASSIGNMENT_COMPANY_MISSING', $this->codes($all, $noCompany->id));
        $this->assertContains('ASSIGNMENT_SALARY_MISSING', $this->codes($all, $noSalary->id));
        $this->assertContains('DATES_INCONSISTENT', $this->codes($all, $pastEnd->id));
        $this->assertContains('ACTIVE_IN_PIPELINE', $this->codes($all, $piped->id));

        $this->assertSame('needs_review', $this->find($all, 'ACTIVE_IN_PIPELINE')['confidence'], 'an imported record: HR to review');
        DB::table('employees')->where('id', $piped->id)->update(['registration_origin' => 'new']);
        $this->assertSame('confirmed', $this->find($this->findings(), 'ACTIVE_IN_PIPELINE', $piped->id)['confidence'], 'a new registration is bound by the workflow');

        foreach (['EMPLOYEE_NOT_ACTIVE', 'ASSIGNMENT_SALARY_MISSING', 'DATES_INCONSISTENT', ...($companyCheck ? ['ASSIGNMENT_COMPANY_MISSING'] : [])] as $code) {
            $this->assertSame('workflow', $this->find($all, $code)['category']);
            $this->assertSame('confirmed', $this->find($all, $code)['confidence']);
        }
    }

    public function test_replacement_and_succession_inconsistencies_are_found(): void
    {
        [$holder, $original] = $this->sound('Holder');

        $makeReplacement = function (string $name, ?int $replaces, string $start, ?string $end, string $type = 'permanent') use ($original): array {
            $e = $this->makeEmployee('active', $type);
            $e->forceFill(['full_name' => $name, 'location' => 'Mogadishu', 'gender' => 'male'])->save();
            $a = $this->makeAssignment($e, $this->company, null, 200, $start);
            $a->update(['assignment_type' => 'replacement', 'replaces_assignment_id' => $replaces, 'end_date' => $end, 'position_id' => $original->position_id, 'shift' => 'day']);

            return [$e, $a];
        };

        [$unlinked] = $makeReplacement('Unlinked Replacement', null, '2026-10-05', '2026-10-06');
        [$tempReplacement] = $makeReplacement('Temp As Replacement', $original->id, '2026-10-05', '2026-10-06', 'temporary');
        [$notAway] = $makeReplacement('Not Away', $original->id, '2026-10-10', '2026-10-11');
        [$properEmployee, $proper] = $makeReplacement('Proper Cover', $original->id, '2026-10-20', '2026-10-21');
        AssignmentAbsence::query()->create(['work_assignment_id' => $original->id, 'last_working_day' => '2026-10-19', 'absent_from' => '2026-10-20', 'returned_on' => '2026-10-22', 'created_by' => $this->hrManager->id]);

        $all = $this->findings();

        $this->assertContains('REPLACEMENT_LINK_MISSING', $this->codes($all, $unlinked->id));
        $this->assertContains('REPLACEMENT_IS_TEMPORARY_EMPLOYEE', $this->codes($all, $tempReplacement->id));
        $this->assertContains('REPLACEMENT_NOT_IN_ABSENCE', $this->codes($all, $notAway->id));
        $this->assertNotContains('REPLACEMENT_NOT_IN_ABSENCE', $this->codes($all, $properEmployee->id), 'a cover inside a recorded absence is consistent');
        $this->assertSame('Replacement', $this->find($all, 'REPLACEMENT_LINK_MISSING', $unlinked->id)['employment_type']);
        $this->assertNotContains($holder->id, array_column($all, 'employee_id'), 'the holder has nothing wrong');

        // A successor that starts while the previous Permanent is still open.
        [, $before] = $this->sound('Predecessor');
        $next = $this->makeEmployee('active', 'permanent');
        $next->forceFill(['full_name' => 'Early Successor', 'location' => 'Mogadishu', 'gender' => 'male'])->save();
        $successor = $this->makeAssignment($next, $this->company, null, 200, '2026-10-10');
        $successor->update(['replaces_assignment_id' => $before->id, 'position_id' => $this->cleaner->id, 'shift' => 'day']);

        $this->assertContains('SUCCESSOR_OVERLAP', $this->codes($this->findings(), $next->id));
    }

    public function test_a_temporary_cover_that_was_never_approved_is_needs_review_with_its_creator(): void
    {
        [, $position] = $this->sound('Covered Holder');
        $temp = $this->makeEmployee('active', 'temporary');
        $temp->forceFill(['full_name' => 'Temp Cover', 'location' => 'Mogadishu', 'gender' => 'male'])->save();
        TemporaryReplacement::query()->create(['work_assignment_id' => $position->id, 'replacement_employee_id' => $temp->id, 'start_date' => '2026-10-20', 'currency' => 'USD', 'status' => 'active', 'created_by' => $this->hrManager->id]);

        $f = $this->find($this->findings(), 'COVER_NOT_APPROVED', $temp->id);

        $this->assertSame('needs_review', $f['confidence']);
        $this->assertSame('Temporary', $f['employment_type']);
        $this->assertSame('Hodan HR Manager', $f['responsible']['name']);
    }

    // ------------------------------------------------------------------ 4. accountability: only what a record says

    public function test_the_responsible_person_is_named_only_when_a_record_names_them(): void
    {
        $creator = Admin::factory()->create(['role' => AdminRole::HrManager, 'full_name' => 'Creator Carla']);
        [$named, $namedAssignment] = $this->sound('Named', null, $creator->id);
        [$unnamed] = $this->sound('Unnamed', null, null);
        foreach ([$named, $unnamed] as $e) {
            DB::table('employees')->where('id', $e->id)->update(['location' => null]);
        }
        // An unrelated audit entry by someone else must NOT be read as responsibility.
        AuditLog::query()->create(['admin_id' => $this->hrManager->id, 'action' => 'update', 'entity_type' => Employee::class, 'entity_id' => $unnamed->id, 'description' => 'unrelated edit', 'created_at' => now()]);
        DB::table('employee_work_assignments')->where('id', $namedAssignment->id)->update(['salary_amount' => 0]);
        DB::table('employee_work_assignments')->where('employee_id', $unnamed->id)->update(['salary_amount' => 0]);

        $all = $this->findings();

        $salaryNamed = $this->find($all, 'ASSIGNMENT_SALARY_MISSING', $named->id);
        $this->assertSame('Creator Carla', $salaryNamed['responsible']['name']);
        $this->assertSame('Created the assignment', $salaryNamed['responsible']['action']);
        $this->assertNotNull($salaryNamed['responsible']['at']);

        $salaryUnnamed = $this->find($all, 'ASSIGNMENT_SALARY_MISSING', $unnamed->id);
        $this->assertNull($salaryUnnamed['responsible'], 'no record names anybody: "Responsible manager not recorded", nobody is guessed');
        $this->assertNull($this->find($all, 'PROFILE_FIELDS_MISSING', $unnamed->id)['responsible']);
    }

    // ------------------------------------------------------------------ filters, summary, no duplicates

    public function test_filters_and_summary_counts_work_and_an_issue_is_listed_once(): void
    {
        $beta = $this->makeCompany('Beta School');
        $carla = Admin::factory()->create(['role' => AdminRole::HrManager, 'full_name' => 'Creator Carla']);
        [$a, $aAssignment] = $this->sound('Alpha Worker', $this->company, $carla->id);
        [$b] = $this->sound('Beta Worker', $beta, null);
        DB::table('employees')->whereIn('id', [$a->id, $b->id])->update(['location' => null]);
        DB::table('employee_work_assignments')->where('id', $aAssignment->id)->update(['position_id' => null]);
        DB::table('employees')->where('id', $b->id)->update(['registration_origin' => 'new', 'employment_type' => 'temporary']);

        $first = $this->as($this->hr)->getJson(self::URL)->assertOk()->json('data');
        $second = $this->as($this->hr)->getJson(self::URL)->assertOk()->json('data');

        $this->assertSame($first['summary']['total_findings'], $second['summary']['total_findings'], 'scanning again adds nothing: one issue, one finding');
        $this->assertSame(count($first['findings']), count(array_unique(array_column($first['findings'], 'id'))));
        $this->assertSame(2, $first['summary']['active_employees_reviewed']);
        $this->assertSame(2, $first['summary']['incomplete_profiles']);
        $this->assertSame(1, $first['summary']['unmet_job_requirements']);
        $this->assertSame(2, $first['summary']['needs_hr_review'], "the imported worker's missing profile field, and the unrecorded position and shift");
        $this->assertSame($first['summary']['total_findings'], $first['summary']['open']);

        $this->assertSame(['Alpha Hospital'], array_unique(array_column($this->findings(['client_company_id' => $this->company->id]), 'company')));
        $this->assertSame([$b->id], array_values(array_unique(array_column($this->findings(['client_company_id' => $beta->id]), 'employee_id'))));
        $this->assertSame([$a->id], array_values(array_unique(array_column($this->findings(['employee_id' => $a->id]), 'employee_id'))));
        $this->assertSame([$a->id], array_values(array_unique(array_column($this->findings(['search' => $a->employee_number]), 'employee_id'))));
        $this->assertSame([$a->id], array_values(array_unique(array_column($this->findings(['responsible_admin_id' => $carla->id]), 'employee_id'))));
        $unattributed = $this->findings(['responsible_admin_id' => 'none']);
        $this->assertNotEmpty($unattributed);
        $this->assertSame([null], array_values(array_unique(array_column($unattributed, 'responsible'))), 'only findings that no record attributes to anybody');
        $this->assertSame(['Temporary'], array_values(array_unique(array_column($this->findings(['employment_type' => 'Temporary']), 'employment_type'))));
        $this->assertSame(['job_requirements'], array_values(array_unique(array_column($this->findings(['category' => 'job_requirements']), 'category'))));
        $this->assertSame(['needs_review'], array_values(array_unique(array_column($this->findings(['confidence' => 'needs_review']), 'confidence'))));
        $this->assertSame(['high'], array_values(array_unique(array_column($this->findings(['severity' => 'high']), 'severity'))));
        $this->assertSame([], $this->findings(['status' => 'resolved']));
        $this->as($this->hr)->getJson(self::URL.'?category=nonsense')->assertStatus(422);
    }

    // ------------------------------------------------------------------ resolution workflow and audit

    public function test_follow_up_status_and_notes_are_tracked_and_audited(): void
    {
        [$e] = $this->sound('Needs Follow Up');
        DB::table('employees')->where('id', $e->id)->update(['location' => null]);
        $id = $this->find($this->findings(), 'PROFILE_FIELDS_MISSING', $e->id)['id'];

        $this->as($this->hr)->patchJson(self::URL."/{$id}", ['status' => 'resolved'])->assertStatus(422)->assertJsonPath('error_code', 'COMPLIANCE_NOTE_REQUIRED');
        $this->assertSame('open', HrComplianceFinding::query()->find($id)->status);

        $this->as($this->hr)->patchJson(self::URL."/{$id}", ['status' => 'in_progress', 'note' => 'Asked the supervisor for the location'])->assertOk()
            ->assertJsonPath('data.status', 'in_progress')->assertJsonPath('data.note', 'Asked the supervisor for the location');

        $done = $this->as($this->hr)->patchJson(self::URL."/{$id}", ['status' => 'resolved', 'note' => 'Location added to the profile'])->assertOk();
        $this->assertSame('resolved', $done->json('data.status'));
        $this->assertSame('Location added to the profile', $done->json('data.note'));
        $this->assertCount(2, $done->json('data.history'));
        $this->assertSame(['in_progress', 'resolved'], [$done->json('data.history.1.to_status'), $done->json('data.history.0.to_status')]);
        $this->assertSame('Hodan HR Manager', $done->json('data.history.0.by'));

        $row = HrComplianceFinding::query()->findOrFail($id);
        $this->assertSame('manual', $row->resolution_source);
        $this->assertSame($this->hrManager->id, $row->resolved_by);
        $this->assertSame(2, AuditLog::query()->where('entity_type', HrComplianceFinding::class)->where('entity_id', $id)->count());

        // Still detected, but HR resolved it by hand: it stays resolved and is not listed twice.
        $again = $this->findings(['employee_id' => $e->id]);
        $this->assertSame('resolved', $this->find($again, 'PROFILE_FIELDS_MISSING', $e->id)['status']);
        $this->assertSame(1, HrComplianceFinding::query()->where('code', 'PROFILE_FIELDS_MISSING')->where('employee_id', $e->id)->count());
        $this->as($this->hr)->patchJson(self::URL."/{$id}", ['status' => 'bogus'])->assertStatus(422);
    }

    public function test_an_issue_that_is_fixed_is_cleared_by_the_system_and_reopened_if_it_returns(): void
    {
        [$e] = $this->sound('Fixed Then Broken');
        DB::table('employees')->where('id', $e->id)->update(['location' => null]);
        $id = $this->find($this->findings(), 'PROFILE_FIELDS_MISSING', $e->id)['id'];

        DB::table('employees')->where('id', $e->id)->update(['location' => 'Hargeisa']);
        $this->findings();
        $row = HrComplianceFinding::query()->findOrFail($id);
        $this->assertSame(['resolved', 'system_cleared'], [$row->status, $row->resolution_source]);
        $this->assertNull($row->resolved_by, 'resolved by the system, not by a person');

        DB::table('employees')->where('id', $e->id)->update(['location' => null]);
        $this->findings();
        $this->assertSame(['open', null], [HrComplianceFinding::query()->findOrFail($id)->status, HrComplianceFinding::query()->findOrFail($id)->resolution_source]);
        $this->assertSame(1, HrComplianceFinding::query()->where('code', 'PROFILE_FIELDS_MISSING')->where('employee_id', $e->id)->count());
    }

    // ------------------------------------------------------------------ permissions

    public function test_only_hr_management_roles_with_the_report_permission_can_read_and_only_hr_manage_can_update(): void
    {
        [$e] = $this->sound('Guarded');
        DB::table('employees')->where('id', $e->id)->update(['location' => null]);
        $id = $this->find($this->findings(), 'PROFILE_FIELDS_MISSING', $e->id)['id'];
        [, $super] = $this->superAdminToken();
        $hrEmployee = Admin::factory()->create(['role' => AdminRole::HrEmployee]);
        $this->grant($hrEmployee, ['hr.view', 'hr.reports.view', 'hr.manage']);
        $reportsOnly = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->grant($reportsOnly, ['hr.view', 'hr.reports.view']);
        $noPermission = Admin::factory()->create(['role' => AdminRole::HrManager]);

        $this->as($super)->getJson(self::URL)->assertOk();
        $this->as($this->hr)->getJson(self::URL)->assertOk();
        $this->as($reportsOnly->createToken('t')->plainTextToken)->getJson(self::URL)->assertOk();
        $this->as($hrEmployee->createToken('t')->plainTextToken)->getJson(self::URL)->assertForbidden();
        $this->as($noPermission->createToken('t')->plainTextToken)->getJson(self::URL)->assertForbidden();
        $this->as($this->tokenWithOnly(['hr.view', 'hr.reports.view']))->getJson(self::URL)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson(self::URL)->assertUnauthorized();

        $this->as($reportsOnly->createToken('t')->plainTextToken)->patchJson(self::URL."/{$id}", ['status' => 'in_progress'])->assertForbidden();
        $this->as($hrEmployee->createToken('t')->plainTextToken)->patchJson(self::URL."/{$id}", ['status' => 'in_progress'])->assertForbidden();
        $this->as($super)->patchJson(self::URL."/{$id}", ['status' => 'in_progress', 'note' => 'Looking into it'])->assertOk();
        $this->as($this->hr)->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.status', 'in_progress');
    }

    // ------------------------------------------------------------------ the report changes nothing

    public function test_the_report_never_changes_employees_assignments_status_salary_or_payroll(): void
    {
        [$e, $a] = $this->sound('Untouchable');
        DB::table('employees')->where('id', $e->id)->update(['location' => null, 'registration_origin' => 'new', 'pipeline_stage' => 'contract_pending']);
        DB::table('employee_work_assignments')->where('id', $a->id)->update(['salary_amount' => 0, 'end_date' => '2026-10-20']);
        PayrollRun::query()->create(['period' => '2026-10', 'status' => 'draft', 'currency' => 'USD']);
        $state = fn (): array => [
            Employee::query()->orderBy('id')->get()->toArray(),
            EmployeeWorkAssignment::query()->orderBy('id')->get()->toArray(),
            EmployeeStatusHistory::query()->count(),
            PayrollRun::query()->orderBy('id')->get()->toArray(),
            PayrollItem::query()->count(),
            DB::table('assignment_salary_histories')->count(),
            DB::table('employee_payments')->count(),
        ];
        $before = $state();

        $first = $this->as($this->hr)->getJson(self::URL)->assertOk()->json('data');
        $this->as($this->hr)->getJson(self::URL)->assertOk();
        $this->as($this->hr)->patchJson(self::URL.'/'.$first['findings'][0]['id'], ['status' => 'in_progress', 'note' => 'Following up'])->assertOk();

        $this->assertGreaterThan(0, count($first['findings']));
        $this->assertEquals($before, $state(), 'scanning and recording follow-up changed no employee, assignment, status history, salary, payment or payroll record');
        $this->assertSame('active', $e->fresh()->status->value, 'nobody was deactivated, suspended or terminated');
    }
}
