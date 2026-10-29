<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Enums\FacilityDueState;
use App\Enums\FacilityItemKey;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\FacilityComplaint;
use App\Models\FacilityInspection;
use App\Models\FacilityInspectionSchedule;
use App\Models\Permission;
use App\Support\Hr\Facility\FacilityDueCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Facility Inspections (Phase 2): the twelve checked items, required issue + corrective action, uniform issues limited to the company's own
 * assigned staff, per-company schedules with due/overdue/never-inspected states computed when asked (no scheduler), staff and customer complaints
 * where "answered" is not "resolved" and satisfaction is only ever explicit, voiding instead of deleting, and reports built from stored data.
 */
class FacilityInspectionsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const TODAY = '2026-10-21';

    private string $manager;

    private string $viewer;

    private ClientCompany $alpha;

    private ClientCompany $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:00:00', 'UTC'));
        $this->manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.reports.view', 'hr.inspections.view', 'hr.inspections.manage']);
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.reports.view', 'hr.inspections.view']);
        $this->alpha = $this->makeCompany('Alpha Hospital');
        $this->beta = $this->makeCompany('Beta School');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** All twelve items satisfactory unless overridden: [item_key => [applicable, result, issue, action]]. */
    private function items(array $override = []): array
    {
        return array_map(function (FacilityItemKey $key) use ($override): array {
            $o = $override[$key->value] ?? [true, 'satisfactory'];

            return array_filter([
                'item_key' => $key->value,
                'applicable' => $o[0],
                'result' => $o[1] ?? null,
                'issue_description' => $o[2] ?? null,
                'corrective_action' => $o[3] ?? null,
            ], fn ($v) => $v !== null);
        }, FacilityItemKey::cases());
    }

    private function body(ClientCompany $company, array $over = []): array
    {
        return array_replace([
            'client_company_id' => $company->id,
            'inspector_name' => 'Inspector One',
            'inspected_on' => '2026-10-10',
            'general_notes' => 'Routine visit',
            'next_follow_up_on' => null,
            'items' => $this->items(),
            // The inspector's explicit answer to "was there a staff / customer complaint?" (required since the complaint sections were added).
            'staff_complaints_reported' => false,
            'customer_complaints_reported' => false,
        ], $over);
    }

    private function inspect(ClientCompany $company, array $over = []): int
    {
        return (int) $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($company, $over))->assertCreated()->json('data.id');
    }

    private function complain(ClientCompany $company, array $over = []): int
    {
        return (int) $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-complaints', array_replace([
            'client_company_id' => $company->id, 'type' => 'customer', 'details' => 'The toilets were not cleaned', 'reported_on' => '2026-10-12',
        ], $over))->assertCreated()->json('data.id');
    }

    // ------------------------------------------------------------------ due-state rules (pure)

    public function test_due_state_rules(): void
    {
        $today = CarbonImmutable::parse(self::TODAY);
        $eval = fn (?string $last, ?string $followUp, ?int $freq, int $soon = 7) => FacilityDueCalculator::evaluate($today, $last ? CarbonImmutable::parse($last) : null, $followUp ? CarbonImmutable::parse($followUp) : null, $freq, $soon);

        $this->assertSame(FacilityDueState::NeverInspected, $eval(null, null, 30)['state']);
        $this->assertSame(FacilityDueState::NoSchedule, $eval('2026-10-01', null, null)['state'], 'inspected, but no frequency and no follow-up');
        $this->assertSame(FacilityDueState::Overdue, $eval('2026-09-01', null, 30)['state'], 'due 2026-10-01');
        $this->assertSame(FacilityDueState::Due, $eval('2026-09-21', null, 30)['state'], 'due exactly today');
        $this->assertSame(FacilityDueState::DueSoon, $eval('2026-09-28', null, 30)['state'], 'due in 7 days');
        $this->assertSame(FacilityDueState::Ok, $eval('2026-10-20', null, 30)['state']);
        $this->assertSame(FacilityDueState::Ok, $eval('2026-09-28', null, 30, 3)['state'], 'a narrower "due soon" window');
        $this->assertSame('2026-10-22', $eval('2026-10-01', '2026-10-22', 365)['next_due_on']->toDateString(), 'the inspector\'s follow-up date wins over the frequency');
        $this->assertSame(FacilityDueState::DueSoon, $eval('2026-10-01', '2026-10-22', 365)['state']);
        $this->assertSame(3, $eval('2026-10-18', null, 30)['days_since_last']);
    }

    // ------------------------------------------------------------------ access

    public function test_reading_needs_view_writing_needs_manage_and_the_report_needs_both_view_and_reports(): void
    {
        $none = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $reportOnly = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.reports.view']);

        $this->as($none)->getJson('/api/v1/admin/hr/facility-inspections')->assertForbidden();
        $this->as($none)->getJson('/api/v1/admin/hr/facility-inspections/status')->assertForbidden();
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections')->assertOk();
        $this->as($this->viewer)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha))->assertForbidden();
        $this->as($this->viewer)->postJson('/api/v1/admin/hr/facility-complaints', ['client_company_id' => $this->alpha->id, 'type' => 'staff', 'details' => 'Something wrong', 'reported_on' => '2026-10-12'])->assertForbidden();
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility')->assertOk();
        $this->as($reportOnly)->getJson('/api/v1/admin/hr/reports/facility')->assertForbidden();
        $this->as($this->tokenForRoleWith(AdminRole::HrManager, ['hr.inspections.view']))->getJson('/api/v1/admin/hr/reports/facility')->assertForbidden();
        [, $super] = $this->superAdminToken();
        $this->as($super)->getJson('/api/v1/admin/hr/facility-inspections/status')->assertOk();
        $this->as($this->noPermissionToken())->getJson('/api/v1/admin/hr/facility-complaints')->assertForbidden();
    }

    // ------------------------------------------------------------------ the inspection

    public function test_the_hr_manager_role_as_the_migration_leaves_it_manages_inspections_and_gains_nothing_else(): void
    {
        $id = fn (string $key): int => (int) Permission::query()->where('key', $key)->value('id');
        $roleKeys = fn (string $role): array => DB::table('admin_role_permissions')->join('permissions', 'permissions.id', '=', 'admin_role_permissions.permission_id')
            ->where('admin_role_permissions.role', $role)->orderBy('permissions.key')->pluck('permissions.key')->all();

        // The HR Manager ROLE as in production (role-level grants only, no direct grants), then the Phase 2 migration.
        foreach (['hr.view', 'hr.manage', 'hr.reports.view'] as $key) {
            DB::table('admin_role_permissions')->insert(['role' => 'hr_manager', 'permission_id' => $id($key), 'created_at' => now(), 'updated_at' => now()]);
        }
        $marketingBefore = $roleKeys('marketing_manager');
        (require base_path('database/migrations/2026_10_21_100000_add_hr_dashboard_and_inspection_permissions.php'))->up();

        $this->assertSame(['hr.dashboard.view', 'hr.inspections.manage', 'hr.inspections.view', 'hr.manage', 'hr.reports.view', 'hr.view'], $roleKeys('hr_manager'), 'exactly the three approved keys were added');
        $this->assertSame($marketingBefore, $roleKeys('marketing_manager'), 'no other role changed');

        $hrManager = Admin::factory()->create(['role' => AdminRole::HrManager])->createToken('t')->plainTextToken;
        $inspection = (int) $this->as($hrManager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha))->assertCreated()->json('data.id');
        $this->as($hrManager)->putJson("/api/v1/admin/hr/facility-inspections/{$inspection}", ['general_notes' => 'Edited by the HR Manager role'])->assertOk();
        $this->as($hrManager)->putJson('/api/v1/admin/hr/facility-inspection-schedules/'.$this->alpha->id, ['frequency_days' => 30])->assertOk();
        $this->as($hrManager)->getJson('/api/v1/admin/hr/reports/facility')->assertOk();
        $this->as($hrManager)->postJson("/api/v1/admin/hr/facility-inspections/{$inspection}/void", ['reason' => 'Recorded for the wrong site'])->assertOk();

        // Least privilege: hr.manage alone is not enough, and other roles stay out.
        $manageOnly = $this->tokenForRoleWith(AdminRole::Manager, ['hr.view', 'hr.manage']);
        $this->as($manageOnly)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha))->assertForbidden();
        $marketing = Admin::factory()->create(['role' => AdminRole::MarketingManager])->createToken('t')->plainTextToken;
        $this->as($marketing)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha))->assertForbidden();
        $this->as($marketing)->getJson('/api/v1/admin/hr/facility-inspections')->assertForbidden();
    }

    public function test_an_inspection_is_recorded_with_all_twelve_items_and_audited(): void
    {
        $id = $this->inspect($this->alpha, ['items' => $this->items([
            'toilets' => [true, 'unsatisfactory', 'Dirty floor and no soap', 'Deep clean today and restock soap'],
            'plants' => [false, null],
        ]), 'next_follow_up_on' => '2026-11-10']);

        $detail = $this->as($this->viewer)->getJson("/api/v1/admin/hr/facility-inspections/{$id}")->assertOk()->json('data');

        $this->assertCount(12, $detail['items']);
        $byKey = collect($detail['items'])->keyBy('item_key');
        $this->assertSame('unsatisfactory', $byKey['toilets']['result']);
        $this->assertSame('Dirty floor and no soap', $byKey['toilets']['issue_description']);
        $this->assertFalse($byKey['plants']['applicable']);
        $this->assertNull($byKey['plants']['result']);
        $this->assertSame('satisfactory', $byKey['windows']['result']);
        $this->assertSame(1, $detail['unsatisfactory_count']);
        $this->assertSame('Alpha Hospital', $detail['company']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => FacilityInspection::class, 'entity_id' => $id, 'action' => 'create']);
        $log = AuditLog::query()->where('entity_type', FacilityInspection::class)->first();
        $this->assertSame(['toilets'], $log->metadata['unsatisfactory_items']);
        $this->assertSame(['plants'], $log->metadata['not_applicable_items']);
    }

    public function test_an_unsatisfactory_item_needs_an_issue_and_a_corrective_action_and_every_item_must_be_answered(): void
    {
        $post = fn (array $over) => $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, $over));

        $post(['items' => $this->items(['mirrors' => [true, 'unsatisfactory']])])->assertUnprocessable();
        $post(['items' => $this->items(['mirrors' => [true, 'unsatisfactory', 'Cracked']])])->assertUnprocessable();
        $post(['items' => $this->items(['mirrors' => [true, 'unsatisfactory', null, 'Replace it']])])->assertUnprocessable();
        $post(['items' => $this->items(['mirrors' => [true, null]])])->assertUnprocessable();   // applies but no outcome
        $post(['items' => $this->items(['mirrors' => [false, 'satisfactory']])])->assertUnprocessable();   // not applicable but has an outcome
        $post(['items' => array_slice($this->items(), 0, 11)])->assertUnprocessable();   // one item missing
        $post(['inspected_on' => '2026-10-30'])->assertUnprocessable();   // in the future
        $post(['next_follow_up_on' => '2026-10-01'])->assertUnprocessable();   // before the visit
        $post(['inspector_name' => ''])->assertUnprocessable();
        $this->assertSame(0, FacilityInspection::query()->count(), 'nothing was saved by any refused attempt');

        $post(['items' => $this->items(['mirrors' => [true, 'unsatisfactory', 'Cracked', 'Replace it']])])->assertCreated();
    }

    public function test_a_uniform_issue_can_only_name_an_employee_who_works_for_that_company_on_the_visit_date(): void
    {
        $ours = $this->makeEmployee('active', 'permanent', '615000111');
        $this->makeAssignment($ours, $this->alpha, null, 200, '2026-01-01');
        $theirs = $this->makeEmployee('active', 'permanent', '615000222');
        $this->makeAssignment($theirs, $this->beta, null, 200, '2026-01-01');
        $later = $this->makeEmployee('active', 'permanent', '615000333');
        $this->makeAssignment($later, $this->alpha, null, 200, '2026-10-15');   // starts after the visit
        $issue = fn (Employee $e) => ['employee_id' => $e->id, 'issue' => 'No uniform worn', 'warning_given' => true, 'reason_not_worn' => 'Lost it', 'corrective_action' => 'New uniform', 'follow_up_on' => '2026-10-20'];

        foreach ([$theirs, $later] as $wrong) {
            $r = $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, ['uniform_issues' => [$issue($wrong)]]))->assertUnprocessable();
            $this->assertSame('UNIFORM_EMPLOYEE_NOT_ASSIGNED', $r->json('error_code'));
            $this->assertStringContainsString($wrong->employee_number, $r->json('message'));
        }
        $this->assertSame(0, FacilityInspection::query()->count());

        $id = $this->inspect($this->alpha, ['uniform_issues' => [$issue($ours)]]);
        $row = $this->as($this->viewer)->getJson("/api/v1/admin/hr/facility-inspections/{$id}")->json('data.uniform_issues.0');
        $this->assertSame($ours->employee_number, $row['employee_number']);
        $this->assertTrue($row['warning_given']);
        $this->assertSame('Lost it', $row['reason_not_worn']);
        $this->assertSame('2026-10-20', $row['follow_up_on']);

        $names = $this->as($this->manager)->getJson("/api/v1/admin/hr/facility-inspections/company-employees/{$this->alpha->id}?on=2026-10-10")->assertOk()->json('data');
        $this->assertSame([$ours->employee_number], array_column($names, 'employee_number'), 'the picker offers only this company\'s people');
    }

    public function test_a_workplace_must_belong_to_the_inspected_company(): void
    {
        $theirs = $this->makeClientLocation($this->beta, 'Beta Main');
        $ours = $this->makeClientLocation($this->alpha, 'Alpha Wing');

        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, ['work_location_id' => $theirs->id]))->assertUnprocessable()->assertJsonPath('error_code', 'WORKPLACE_NOT_OF_COMPANY');
        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, ['work_location_id' => $ours->id]))->assertCreated()->assertJsonPath('data.workplace', 'Alpha Wing');
    }

    public function test_an_inspection_can_be_corrected_but_never_moved_to_another_company_and_a_voided_one_is_frozen(): void
    {
        $id = $this->inspect($this->alpha);

        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", [
            'general_notes' => 'Corrected note', 'client_company_id' => $this->beta->id,
            'items' => $this->items(['doors' => [true, 'unsatisfactory', 'Door sticks', 'Plane the door']]),
        ])->assertOk()->assertJsonPath('data.general_notes', 'Corrected note')->assertJsonPath('data.unsatisfactory_count', 1);
        $this->assertSame($this->alpha->id, FacilityInspection::query()->find($id)->client_company_id, 'the company is not changed by an edit');
        $log = AuditLog::query()->where('entity_type', FacilityInspection::class)->where('action', 'update')->first();
        $this->assertSame([], $log->metadata['before']['unsatisfactory_items']);
        $this->assertSame(['doors'], $log->metadata['after']['unsatisfactory_items']);

        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-inspections/{$id}/void", ['reason' => ''])->assertUnprocessable();
        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-inspections/{$id}/void", ['reason' => 'Recorded for the wrong site'])->assertOk()->assertJsonPath('data.voided', true);
        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['general_notes' => 'x'])->assertUnprocessable()->assertJsonPath('error_code', 'FACILITY_INSPECTION_VOIDED');
        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-inspections/{$id}/void", ['reason' => 'Again for no reason'])->assertUnprocessable();
        $this->assertNotNull(FacilityInspection::query()->find($id), 'voiding never deletes');
    }

    // ------------------------------------------------------------------ schedules and states

    public function test_the_status_board_shows_never_inspected_overdue_due_soon_and_up_to_date_from_stored_data(): void
    {
        $gamma = $this->makeCompany('Gamma Hotel');
        $delta = $this->makeCompany('Delta Mall');
        $this->inspect($this->alpha, ['inspected_on' => '2026-09-01']);   // frequency 30 -> due 2026-10-01 -> overdue
        $this->inspect($gamma, ['inspected_on' => '2026-09-28']);         // frequency 30 -> due 2026-10-28 -> due soon (7 days)
        $this->inspect($delta, ['inspected_on' => '2026-10-20']);         // frequency 30 -> up to date
        foreach ([$this->alpha, $gamma, $delta] as $company) {
            $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspection-schedules/{$company->id}", ['frequency_days' => 30])->assertOk();
        }
        // $this->beta: never inspected

        $response = $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/status')->assertOk();
        $rows = collect($response->json('data'))->keyBy('company');

        $this->assertSame('overdue', $rows['Alpha Hospital']['state']);
        $this->assertSame('2026-10-01', $rows['Alpha Hospital']['next_due_on']);
        $this->assertSame('due_soon', $rows['Gamma Hotel']['state']);
        $this->assertSame('ok', $rows['Delta Mall']['state']);
        $this->assertSame('never_inspected', $rows['Beta School']['state']);
        $this->assertNull($rows['Beta School']['next_due_on'], 'never inspected: no date is invented');
        $this->assertSame(['Alpha Hospital', 'Beta School'], array_slice(array_column($response->json('data'), 'company'), 0, 2), 'most urgent first');
        $this->assertSame(1, $response->json('meta.counts.overdue'));
        $this->assertSame(1, $response->json('meta.counts.never_inspected'));
        $this->assertStringContainsString('No automatic reminder is sent', $response->json('meta.reminders'));

        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/status?state=overdue')->assertJsonCount(1, 'data');
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/status?search=gamma')->assertJsonCount(1, 'data');
    }

    public function test_a_voided_inspection_does_not_count_and_the_inspectors_follow_up_date_sets_the_next_due_date(): void
    {
        $id = $this->inspect($this->alpha, ['inspected_on' => '2026-10-05', 'next_follow_up_on' => '2026-10-19']);
        $row = fn () => collect($this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/status?client_company_id='.$this->alpha->id)->json('data'))->first();

        $this->assertSame('overdue', $row()['state'], 'the follow-up date of 19 Oct has passed');
        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-inspections/{$id}/void", ['reason' => 'Wrong company chosen'])->assertOk();
        $this->assertSame('never_inspected', $row()['state'], 'a voided inspection is not an inspection');
        $this->assertSame(0, $row()['inspection_count']);
    }

    public function test_the_schedule_is_validated_audited_and_changes_no_past_inspection(): void
    {
        $this->inspect($this->alpha);
        $url = "/api/v1/admin/hr/facility-inspection-schedules/{$this->alpha->id}";

        foreach ([0, -5, 1000, 'abc'] as $bad) {
            $this->as($this->manager)->putJson($url, ['frequency_days' => $bad])->assertUnprocessable();
        }
        $this->as($this->manager)->putJson($url, ['frequency_days' => 14, 'due_soon_days' => 3])->assertOk()->assertJsonPath('data.frequency_days', 14);
        $this->as($this->manager)->putJson($url, ['frequency_days' => 60])->assertOk();

        $logs = AuditLog::query()->where('entity_type', FacilityInspectionSchedule::class)->orderBy('id')->get();
        $this->assertCount(2, $logs);
        $this->assertSame(14, $logs[1]->metadata['before']['frequency_days']);
        $this->assertSame(60, $logs[1]->metadata['after']['frequency_days']);
        $this->assertSame(1, FacilityInspection::query()->count());
        $this->as($this->viewer)->putJson($url, ['frequency_days' => 7])->assertForbidden();
    }

    // ------------------------------------------------------------------ complaints

    public function test_a_complaint_always_starts_open_and_the_status_cannot_be_set_when_registering_or_editing(): void
    {
        $url = '/api/v1/admin/hr/facility-complaints';
        $body = ['client_company_id' => $this->alpha->id, 'type' => 'staff', 'details' => 'No gloves provided', 'reported_on' => '2026-10-12'];

        $this->as($this->manager)->postJson($url, $body + ['status' => 'resolved'])->assertUnprocessable();
        $this->as($this->manager)->postJson($url, $body + ['satisfaction' => 'satisfied'])->assertUnprocessable();
        $id = $this->as($this->manager)->postJson($url, $body + ['response' => 'We ordered gloves'])->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');
        $this->assertNotNull(FacilityComplaint::query()->find($id)->responded_on);
        $this->as($this->manager)->putJson("{$url}/{$id}", ['status' => 'resolved'])->assertUnprocessable();
        $this->assertSame('open', FacilityComplaint::query()->find($id)->status->value, 'a response does not answer or resolve the complaint');
        $this->as($this->manager)->postJson($url, array_replace($body, ['type' => 'neighbour']))->assertUnprocessable();
    }

    public function test_answered_is_not_resolved_and_every_step_is_explicit_and_written_to_the_history(): void
    {
        $id = $this->complain($this->alpha);
        $url = "/api/v1/admin/hr/facility-complaints/{$id}";
        $step = fn (array $body) => $this->as($this->manager)->postJson("{$url}/status", $body);

        $step(['status' => 'answered'])->assertUnprocessable()->assertJsonPath('error_code', 'COMPLAINT_RESPONSE_REQUIRED');
        $this->as($this->manager)->putJson($url, ['response' => 'We cleaned the toilets and re-checked them', 'responsible_person' => 'Site supervisor'])->assertOk()->assertJsonPath('data.status', 'open');
        $step(['status' => 'in_progress', 'note' => 'Supervisor on site'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $step(['status' => 'answered'])->assertOk()->assertJsonPath('data.status', 'answered')->assertJsonPath('data.resolved_on', null);
        $this->assertSame(['answered'], array_values(array_unique(FacilityComplaint::query()->where('id', $id)->pluck('status')->map->value->all())));

        $step(['status' => 'resolved'])->assertUnprocessable()->assertJsonPath('error_code', 'COMPLAINT_NOTE_REQUIRED');
        $step(['status' => 'resolved', 'note' => 'Client confirmed on the phone'])->assertOk()->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolved_on', self::TODAY);
        $step(['status' => 'in_progress', 'note' => 'again'])->assertUnprocessable()->assertJsonPath('error_code', 'COMPLAINT_TRANSITION_NOT_ALLOWED');
        $step(['status' => 'open', 'note' => ''])->assertUnprocessable();
        $step(['status' => 'open', 'note' => 'The problem came back'])->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolved_on', null);

        $events = $this->as($this->viewer)->getJson($url)->json('data.events');
        $this->assertSame(['created', 'response_recorded', 'status_changed', 'status_changed', 'status_changed', 'status_changed'], array_column($events, 'event'));
        $this->assertSame(['in_progress', 'answered', 'resolved', 'open'], array_column(array_slice($events, 2), 'to_status'));
        $this->assertSame('Client confirmed on the phone', $events[4]['note']);
        $this->assertNotNull($events[2]['by']);
    }

    public function test_super_admin_can_view_respond_change_status_and_record_the_resolution_of_a_complaint(): void
    {
        [$superAdmin, $super] = $this->superAdminToken();
        $id = $this->complain($this->alpha);
        $url = "/api/v1/admin/hr/facility-complaints/{$id}";

        $this->as($super)->getJson('/api/v1/admin/hr/facility-complaints')->assertOk()->assertJsonFragment(['id' => $id]);
        $this->as($super)->getJson($url)->assertOk()->assertJsonPath('data.status', 'open');
        $this->as($super)->putJson($url, ['response' => 'Supervisor visited and re-cleaned', 'responsible_person' => 'Site supervisor'])->assertOk();
        $this->as($super)->postJson("{$url}/status", ['status' => 'in_progress', 'note' => 'Being handled'])->assertOk()->assertJsonPath('data.status', 'in_progress');
        $this->as($super)->postJson("{$url}/status", ['status' => 'resolved', 'note' => 'Client confirmed by phone'])->assertOk()
            ->assertJsonPath('data.status', 'resolved')->assertJsonPath('data.resolved_on', self::TODAY);

        // Every step is on the history with the Super Admin's name (who) and the note (what was decided).
        $events = $this->as($super)->getJson($url)->json('data.events');
        $this->assertContains('status_changed', array_column($events, 'event'));
        $this->assertContains('Client confirmed by phone', array_column($events, 'note'));
        $this->assertContains($superAdmin->full_name, array_column($events, 'by'));
    }

    public function test_satisfaction_is_recorded_only_from_explicit_feedback_and_is_never_inferred(): void
    {
        $id = $this->complain($this->alpha);
        $url = "/api/v1/admin/hr/facility-complaints/{$id}";
        $this->as($this->manager)->putJson($url, ['response' => 'Done and checked'])->assertOk();
        $this->as($this->manager)->postJson("{$url}/status", ['status' => 'resolved', 'note' => 'Fixed on site today'])->assertOk();

        $show = $this->as($this->viewer)->getJson($url)->assertOk()->json('data');
        $this->assertNull($show['satisfaction'], 'resolved and answered are not satisfaction');
        $this->assertSame('Not recorded', $show['satisfaction_label']);

        $this->as($this->manager)->postJson("{$url}/satisfaction", ['satisfaction' => 'satisfied'])->assertUnprocessable();
        $this->as($this->manager)->postJson("{$url}/satisfaction", ['satisfaction' => 'satisfied', 'evidence' => 'ok'])->assertUnprocessable();
        $this->as($this->manager)->postJson("{$url}/satisfaction", ['satisfaction' => 'maybe', 'evidence' => 'Manager said so on the phone'])->assertUnprocessable();
        $this->assertNull(FacilityComplaint::query()->find($id)->satisfaction);

        $this->as($this->manager)->postJson("{$url}/satisfaction", ['satisfaction' => 'satisfied', 'evidence' => 'Client manager confirmed by phone on 21 Oct'])->assertOk()
            ->assertJsonPath('data.satisfaction', 'satisfied')->assertJsonPath('data.status', 'resolved');
        $this->as($this->manager)->postJson("{$url}/satisfaction", ['satisfaction' => null, 'evidence' => 'Recorded against the wrong complaint'])->assertOk()->assertJsonPath('data.satisfaction', null);
        $events = collect($this->as($this->viewer)->getJson($url)->json('data.events'))->pluck('event')->all();
        $this->assertContains('satisfaction_recorded', $events);
        $this->assertContains('satisfaction_cleared', $events);
    }

    public function test_a_staff_reporter_must_work_for_that_company_and_a_linked_inspection_must_be_the_same_companys(): void
    {
        $ours = $this->makeEmployee('active', 'permanent', '615000111');
        $this->makeAssignment($ours, $this->alpha, null, 200, '2026-01-01');
        $theirs = $this->makeEmployee('active', 'permanent', '615000222');
        $this->makeAssignment($theirs, $this->beta, null, 200, '2026-01-01');
        $inspectionOfBeta = $this->inspect($this->beta);
        $url = '/api/v1/admin/hr/facility-complaints';
        $body = ['client_company_id' => $this->alpha->id, 'type' => 'staff', 'details' => 'Wages for the site were late', 'reported_on' => '2026-10-12'];

        $this->as($this->manager)->postJson($url, $body + ['reporter_employee_id' => $theirs->id])->assertUnprocessable()->assertJsonPath('error_code', 'UNIFORM_EMPLOYEE_NOT_ASSIGNED');
        $this->as($this->manager)->postJson($url, $body + ['facility_inspection_id' => $inspectionOfBeta])->assertUnprocessable()->assertJsonPath('error_code', 'INSPECTION_NOT_OF_COMPANY');
        $this->as($this->manager)->postJson($url, $body + ['reporter_employee_id' => $ours->id, 'reporter_type' => 'employee'])->assertCreated()->assertJsonPath('data.reporter_employee', "{$ours->full_name} ({$ours->employee_number})");
    }

    public function test_a_voided_complaint_is_frozen_kept_and_not_counted(): void
    {
        $id = $this->complain($this->alpha);
        $url = "/api/v1/admin/hr/facility-complaints/{$id}";

        $this->as($this->manager)->postJson("{$url}/void", ['reason' => 'x'])->assertUnprocessable();
        $this->as($this->manager)->postJson("{$url}/void", ['reason' => 'Registered twice by mistake'])->assertOk()->assertJsonPath('data.voided', true);
        $this->as($this->manager)->putJson($url, ['details' => 'changed text'])->assertUnprocessable();
        $this->as($this->manager)->postJson("{$url}/status", ['status' => 'in_progress'])->assertUnprocessable();
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-complaints')->assertJsonCount(0, 'data');
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-complaints?include_voided=1')->assertJsonCount(1, 'data');
        $this->assertSame(0, $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility')->json('data.complaints.total'));
    }

    // ------------------------------------------------------------------ reports

    public function test_the_report_is_built_from_real_stored_data_and_respects_the_filters(): void
    {
        $this->inspect($this->alpha, ['inspected_on' => '2026-08-10', 'items' => $this->items(['toilets' => [true, 'unsatisfactory', 'Dirty', 'Clean it'], 'fans' => [false, null]])]);
        $this->inspect($this->alpha, ['inspected_on' => '2026-10-10']);
        $this->inspect($this->beta, ['inspected_on' => '2026-10-11']);
        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspection-schedules/{$this->alpha->id}", ['frequency_days' => 30])->assertOk();
        $gamma = $this->makeCompany('Gamma Hotel');   // never inspected

        $open = $this->complain($this->alpha, ['type' => 'customer', 'reported_on' => '2026-10-01']);
        $answered = $this->complain($this->alpha, ['type' => 'staff', 'reported_on' => '2026-10-02', 'response' => 'We spoke to them']);
        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-complaints/{$answered}/status", ['status' => 'answered'])->assertOk();
        $resolved = $this->complain($this->beta, ['type' => 'customer', 'reported_on' => '2026-09-20', 'response' => 'Cleaned']);
        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-complaints/{$resolved}/status", ['status' => 'resolved', 'note' => 'Verified on a re-visit', 'resolved_on' => '2026-09-25'])->assertOk();
        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-complaints/{$resolved}/satisfaction", ['satisfaction' => 'not_satisfied', 'evidence' => 'Client wrote that it took too long'])->assertOk();
        $voided = $this->complain($this->beta, ['reported_on' => '2026-10-03']);
        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-complaints/{$voided}/void", ['reason' => 'Duplicate entry'])->assertOk();

        $data = $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility?from=2026-08-01&to=2026-10-21')->assertOk()->json('data');

        $this->assertSame(3, $data['inspections']['count']);
        $this->assertSame(2, $data['inspections']['companies_inspected']);
        $this->assertSame(1, $data['inspections']['never_inspected'], 'Gamma Hotel has never been inspected');
        $this->assertSame(3, $data['complaints']['total'], 'the voided one is left out');
        $this->assertSame(['open' => 1, 'in_progress' => 0, 'answered' => 1, 'resolved' => 1], $data['complaints']['by_status']);
        $this->assertSame(1, $data['complaints']['answered']);
        $this->assertSame(2, $data['complaints']['unresolved'], 'answered still counts as unresolved');
        $this->assertSame(['satisfied' => 0, 'not_satisfied' => 1, 'not_recorded' => 2], $data['satisfaction'], 'missing feedback is "not recorded", never a positive or a negative');
        $this->assertEquals(5.0, $data['complaints']['average_days_to_resolution']);
        $this->assertSame(1, $data['items']['unsatisfactory_total']);
        $this->assertSame(1, collect($data['items']['by_item'])->firstWhere('item_key', 'toilets')['unsatisfactory']);
        $this->assertSame(3, collect($data['items']['by_item'])->firstWhere('item_key', 'toilets')['checked'], 'toilets applied at all three visits');
        $this->assertSame(2, collect($data['items']['by_item'])->firstWhere('item_key', 'fans')['checked'], 'fans were marked not applicable at one visit, so only two count');
    }

    public function test_report_filters_by_company_type_and_period_and_shows_trends_and_infrequent_companies(): void
    {
        $this->inspect($this->alpha, ['inspected_on' => '2026-08-10']);
        $this->inspect($this->beta, ['inspected_on' => '2026-10-11']);
        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspection-schedules/{$this->alpha->id}", ['frequency_days' => 30])->assertOk();
        $this->complain($this->alpha, ['type' => 'customer', 'reported_on' => '2026-10-01']);
        $this->complain($this->alpha, ['type' => 'staff', 'reported_on' => '2026-09-02']);
        $this->complain($this->beta, ['type' => 'staff', 'reported_on' => '2026-10-05']);

        $byCompany = $this->as($this->viewer)->getJson("/api/v1/admin/hr/reports/facility?client_company_id={$this->alpha->id}&from=2026-08-01&to=2026-10-21")->json('data');
        $this->assertSame(1, $byCompany['inspections']['count']);
        $this->assertSame(2, $byCompany['complaints']['total']);

        $staffOnly = $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility?type=staff&from=2026-08-01&to=2026-10-21')->json('data');
        $this->assertSame(2, $staffOnly['complaints']['total']);
        $this->assertSame(['total' => 0, 'unresolved' => 0], $staffOnly['complaints']['by_type']['customer']);

        $period = $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility?from=2026-10-01&to=2026-10-21')->json('data');
        $this->assertSame(1, $period['inspections']['count']);
        $this->assertSame(2, $period['complaints']['total']);

        $wide = $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility?from=2026-08-01&to=2026-10-21')->json('data');
        $this->assertSame(['2026-08', '2026-09', '2026-10'], array_column($wide['trends'], 'month'));
        $this->assertSame([1, 0, 1], array_column($wide['trends'], 'inspections'));
        $this->assertSame([0, 1, 2], array_column($wide['trends'], 'complaints_reported'));
        // Alpha asks for an inspection every 30 days; over 82 days two were expected but only one happened. Beta has no frequency: not judged.
        $this->assertSame(['Alpha Hospital'], array_column($wide['infrequent'], 'company'));
        $this->assertSame([2, 1], [$wide['infrequent'][0]['expected_inspections'], $wide['infrequent'][0]['actual_inspections']]);
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility?client_company_id=999999')->assertUnprocessable();
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/reports/facility?from=2026-10-10&to=2026-10-01')->assertUnprocessable();
    }

    public function test_the_history_list_can_be_filtered_by_company_period_and_findings(): void
    {
        $this->inspect($this->alpha, ['inspected_on' => '2026-10-01', 'items' => $this->items(['walls' => [true, 'unsatisfactory', 'Mould', 'Repaint']])]);
        $this->inspect($this->alpha, ['inspected_on' => '2026-10-08']);
        $this->inspect($this->beta, ['inspected_on' => '2026-10-09']);

        $count = fn (string $query) => count($this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections'.$query)->assertOk()->json('data'));

        $this->assertSame(3, $count(''));
        $this->assertSame(2, $count("?client_company_id={$this->alpha->id}"));
        $this->assertSame(1, $count('?has_issues=1'));
        $this->assertSame(2, $count('?has_issues=0'));
        $this->assertSame(2, $count('?from=2026-10-08&to=2026-10-09'));
        $this->assertSame('2026-10-09', $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections')->json('data.0.inspected_on'), 'newest first');
    }
}
