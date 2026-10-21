<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Enums\FacilityItemKey;
use App\Models\ClientCompany;
use App\Models\FacilityComplaint;
use App\Models\FacilityInspection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Staff and customer complaints recorded inside the Facility Inspection: the inspector must answer "no complaint" or "there is one" for each
 * kind and describe a real complaint completely; the inspection and its complaints are saved together or not at all; "answered" is not
 * "resolved"; satisfaction exists only with written evidence; customers are details, never employees.
 */
class InspectionComplaintsTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/hr/facility-inspections';

    private string $manager;

    private string $viewer;

    private ClientCompany $alpha;

    private ClientCompany $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse('2026-10-21 10:00:00', 'UTC'));
        $this->manager = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.reports.view', 'hr.inspections.view', 'hr.inspections.manage']);
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.inspections.view']);
        $this->alpha = $this->makeCompany('Alpha Hospital');
        $this->beta = $this->makeCompany('Beta School');
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function body(array $over = []): array
    {
        return array_replace([
            'client_company_id' => $this->alpha->id,
            'inspector_name' => 'Inspector One',
            'inspected_on' => '2026-10-10',
            'items' => array_map(fn (FacilityItemKey $k): array => ['item_key' => $k->value, 'applicable' => true, 'result' => 'satisfactory'], FacilityItemKey::cases()),
            'staff_complaints_reported' => false,
            'customer_complaints_reported' => false,
        ], $over);
    }

    private function staffComplaint(int $employeeId, array $over = []): array
    {
        return array_replace(['type' => 'staff', 'details' => 'Gloves were not provided', 'reporter_employee_id' => $employeeId, 'responsible_person' => 'Site supervisor'], $over);
    }

    private function customerComplaint(array $over = []): array
    {
        return array_replace(['type' => 'customer', 'details' => 'Lobby floor left wet', 'reporter_name' => 'Mr Ahmed', 'reporter_type' => 'Hotel manager', 'reporter_phone' => '0612345678', 'responsible_person' => 'Cleaning team lead'], $over);
    }

    private function record(array $over): TestResponse
    {
        return $this->as($this->manager)->postJson(self::URL, $this->body($over));
    }

    public function test_the_inspector_must_answer_both_complaint_questions_and_no_complaint_needs_nothing_more(): void
    {
        $missing = $this->body();
        unset($missing['staff_complaints_reported'], $missing['customer_complaints_reported']);
        $this->as($this->manager)->postJson(self::URL, $missing)->assertUnprocessable()->assertJsonValidationErrors(['staff_complaints_reported', 'customer_complaints_reported'], 'errors');
        $this->assertSame(0, FacilityInspection::query()->count());

        $id = $this->record([])->assertCreated()->assertJsonPath('data.staff_complaints_reported', false)->assertJsonPath('data.customer_complaints_reported', false)->json('data.id');
        $this->assertSame(0, FacilityComplaint::query()->count(), 'no complaint rows are invented');
        $this->as($this->manager)->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.complaints', [])->assertJsonPath('data.staff_complaint_count', 0);
    }

    public function test_an_inspection_saved_before_this_change_says_not_recorded_rather_than_no_complaint(): void
    {
        $old = FacilityInspection::query()->create(['client_company_id' => $this->alpha->id, 'inspector_name' => 'Old', 'inspected_on' => '2026-09-01']);
        $this->as($this->manager)->getJson(self::URL."/{$old->id}")->assertOk()->assertJsonPath('data.staff_complaints_reported', null)->assertJsonPath('data.customer_complaints_reported', null);
    }

    public function test_a_staff_complaint_is_saved_with_the_inspection_linked_to_it_and_the_employee_must_work_for_the_company(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->alpha, null, 200, '2026-01-01');
        $stranger = $this->makeEmployee();
        $this->makeAssignment($stranger, $this->beta, null, 200, '2026-01-01');

        $r = $this->record([
            'staff_complaints_reported' => true,
            'complaints' => [$this->staffComplaint($employee->id, ['corrective_action' => 'Order gloves', 'follow_up_on' => '2026-10-20'])],
        ])->assertCreated();

        $this->assertSame(1, $r->json('data.staff_complaint_count'));
        $this->assertTrue($r->json('data.staff_complaints_reported'));
        $this->assertFalse($r->json('data.customer_complaints_reported'));
        $complaint = FacilityComplaint::query()->firstOrFail();
        $this->assertSame([$this->alpha->id, $r->json('data.id'), 'staff', 'open', $employee->id], [$complaint->client_company_id, $complaint->facility_inspection_id, $complaint->type->value, $complaint->status->value, $complaint->reporter_employee_id]);
        $this->assertSame('Order gloves', $complaint->corrective_action);
        $this->assertNull($complaint->satisfaction, 'satisfaction is never inferred');
        $this->assertDatabaseHas('facility_complaint_events', ['facility_complaint_id' => $complaint->id, 'event' => 'created']);
        $this->assertSame('2026-10-10', $complaint->reported_on->toDateString(), 'the complaint date defaults to the inspection date');

        // The same complaint appears on the Complaints screen and in the report.
        $this->as($this->manager)->getJson('/api/v1/admin/hr/facility-complaints?type=staff')->assertOk()->assertJsonPath('meta.total', 1);
        $this->as($this->manager)->getJson('/api/v1/admin/hr/reports/facility?from=2026-10-01&to=2026-10-21')->assertOk()->assertJsonPath('data.complaints.by_type.staff.total', 1)->assertJsonPath('data.complaints.by_type.customer.total', 0);

        // An employee of another company cannot be the reporter, and nothing at all is saved.
        $before = [FacilityInspection::query()->count(), FacilityComplaint::query()->count()];
        $this->record(['staff_complaints_reported' => true, 'complaints' => [$this->staffComplaint($stranger->id)]])->assertUnprocessable();
        $this->assertSame($before, [FacilityInspection::query()->count(), FacilityComplaint::query()->count()], 'the inspection is rolled back with its complaint');
    }

    public function test_a_customer_complaint_records_customer_details_and_never_an_employee(): void
    {
        $r = $this->record(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint()]])->assertCreated();

        $complaint = FacilityComplaint::query()->firstOrFail();
        $this->assertSame(['customer', 'Mr Ahmed', 'Hotel manager', '0612345678', null], [$complaint->type->value, $complaint->reporter_name, $complaint->reporter_type, $complaint->reporter_phone, $complaint->reporter_employee_id]);
        $this->assertSame(1, $r->json('data.customer_complaint_count'));
        $this->assertSame(0, $r->json('data.staff_complaint_count'));

        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->alpha, null, 200, '2026-01-01');
        $this->record(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint(['reporter_employee_id' => $employee->id])]])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.reporter_employee_id'], 'errors');
        $this->record(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint(['reporter_name' => ''])]])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.reporter_name'], 'errors');
        $this->assertSame(1, FacilityInspection::query()->count());
    }

    public function test_the_answer_and_the_complaints_must_agree_and_an_incomplete_complaint_is_refused(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->alpha, null, 200, '2026-01-01');

        $this->record(['staff_complaints_reported' => true])->assertUnprocessable()->assertJsonValidationErrors(['staff_complaints_reported'], 'errors');
        $this->record(['staff_complaints_reported' => false, 'complaints' => [$this->staffComplaint($employee->id)]])->assertUnprocessable()->assertJsonValidationErrors(['staff_complaints_reported'], 'errors');
        $this->record(['customer_complaints_reported' => false, 'complaints' => [$this->customerComplaint()]])->assertUnprocessable()->assertJsonValidationErrors(['customer_complaints_reported'], 'errors');
        $this->record(['staff_complaints_reported' => true, 'complaints' => [$this->staffComplaint($employee->id, ['details' => 'x', 'responsible_person' => ''])]])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.details', 'complaints.0.responsible_person'], 'errors');
        $this->record(['staff_complaints_reported' => true, 'complaints' => [$this->staffComplaint($employee->id, ['reporter_employee_id' => null])]])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.reporter_employee_id'], 'errors');
        $this->assertSame(0, FacilityInspection::query()->count());
        $this->assertSame(0, FacilityComplaint::query()->count());
    }

    public function test_answered_is_not_resolved_and_each_status_asks_for_what_it_needs(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->alpha, null, 200, '2026-01-01');
        $send = fn (array $complaint) => $this->record(['staff_complaints_reported' => true, 'complaints' => [$this->staffComplaint($employee->id, $complaint)]]);

        $send(['status' => 'answered'])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.response', 'complaints.0.corrective_action'], 'errors');
        $send(['status' => 'resolved', 'response' => 'Gloves delivered', 'corrective_action' => 'Gloves ordered'])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.status_note'], 'errors');
        $this->assertSame(0, FacilityInspection::query()->count());

        $send(['status' => 'answered', 'response' => 'We will order gloves', 'corrective_action' => 'Order gloves'])->assertCreated();
        $answered = FacilityComplaint::query()->firstOrFail();
        $this->assertSame('answered', $answered->status->value);
        $this->assertNull($answered->resolved_on, 'answered is not resolved');
        $this->assertNull($answered->satisfaction);
        $this->assertNotNull($answered->responded_on);

        $send(['status' => 'resolved', 'response' => 'Gloves delivered', 'corrective_action' => 'Gloves delivered', 'status_note' => 'Gloves handed over on site'])->assertCreated();
        $resolved = FacilityComplaint::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame(['resolved', '2026-10-21'], [$resolved->status->value, $resolved->resolved_on->toDateString()]);
        $this->assertSame(2, $resolved->events()->count(), 'created + status_changed are both on its history');

        $send(['status' => 'in_progress'])->assertCreated();
        $this->assertSame('in_progress', FacilityComplaint::query()->orderByDesc('id')->firstOrFail()->status->value);
    }

    public function test_satisfaction_needs_written_evidence_and_is_otherwise_not_recorded(): void
    {
        $this->record(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint(['satisfaction' => 'satisfied'])]])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.satisfaction_evidence'], 'errors');
        $this->record(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint(['satisfaction_evidence' => 'The client said it was fine'])]])->assertUnprocessable()->assertJsonValidationErrors(['complaints.0.satisfaction'], 'errors');

        $this->record(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint()]])->assertCreated();
        $this->assertNull(FacilityComplaint::query()->firstOrFail()->satisfaction, 'no feedback entered -> not recorded, even with a response or a status');

        $this->record(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint(['satisfaction' => 'not_satisfied', 'satisfaction_evidence' => 'Client phoned the office and said it is still dirty'])]])->assertCreated();
        $documented = FacilityComplaint::query()->orderByDesc('id')->firstOrFail();
        $this->assertSame('not_satisfied', $documented->satisfaction->value);
        $this->assertSame('open', $documented->status->value, 'satisfaction does not change the status');
    }

    public function test_both_kinds_can_be_recorded_together_and_one_bad_complaint_rolls_everything_back(): void
    {
        $employee = $this->makeEmployee();
        $this->makeAssignment($employee, $this->alpha, null, 200, '2026-01-01');
        $stranger = $this->makeEmployee();

        $this->record([
            'staff_complaints_reported' => true,
            'customer_complaints_reported' => true,
            'complaints' => [$this->customerComplaint(), $this->staffComplaint($stranger->id)],
        ])->assertUnprocessable();
        $this->assertSame([0, 0], [FacilityInspection::query()->count(), FacilityComplaint::query()->count()]);

        $r = $this->record([
            'staff_complaints_reported' => true,
            'customer_complaints_reported' => true,
            'complaints' => [$this->customerComplaint(), $this->staffComplaint($employee->id)],
        ])->assertCreated();
        $this->assertSame([1, 1], [$r->json('data.staff_complaint_count'), $r->json('data.customer_complaint_count')]);
        $this->assertSame(2, FacilityComplaint::query()->where('facility_inspection_id', $r->json('data.id'))->count());

        $list = $this->as($this->manager)->getJson(self::URL)->assertOk();
        $this->assertSame([1, 1], [$list->json('data.0.staff_complaint_count'), $list->json('data.0.customer_complaint_count')]);
    }

    public function test_the_answer_can_be_corrected_only_when_it_agrees_with_the_complaints_on_record(): void
    {
        $id = $this->record([])->assertCreated()->json('data.id');
        $this->as($this->manager)->putJson(self::URL."/{$id}", ['staff_complaints_reported' => true])->assertUnprocessable()->assertJsonValidationErrors(['staff_complaints_reported'], 'errors');

        // A complaint registered afterwards on the Complaints screen and linked to this inspection makes the answer "yes".
        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-complaints', [
            'client_company_id' => $this->alpha->id, 'facility_inspection_id' => $id, 'type' => 'customer', 'details' => 'Wet floor in the lobby', 'reporter_name' => 'Mr Ahmed',
            'reported_on' => '2026-10-12', 'corrective_action' => 'Mop twice a day', 'follow_up_on' => '2026-10-19',
        ])->assertCreated()->assertJsonPath('data.corrective_action', 'Mop twice a day')->assertJsonPath('data.follow_up_on', '2026-10-19');
        $this->assertTrue(FacilityInspection::query()->findOrFail($id)->customer_complaints_reported);
        $this->assertFalse(FacilityInspection::query()->findOrFail($id)->staff_complaints_reported);

        $this->as($this->manager)->putJson(self::URL."/{$id}", ['customer_complaints_reported' => false])->assertUnprocessable()->assertJsonValidationErrors(['customer_complaints_reported'], 'errors');
        $this->as($this->manager)->putJson(self::URL."/{$id}", ['general_notes' => 'Corrected'])->assertOk()->assertJsonPath('data.customer_complaints_reported', true);

        // The saved record reopens with its complaint.
        $this->as($this->manager)->getJson(self::URL."/{$id}")->assertOk()->assertJsonPath('data.complaints.0.type', 'customer')->assertJsonPath('data.complaints.0.corrective_action', 'Mop twice a day');
    }

    public function test_only_people_who_may_manage_inspections_can_record_complaints_with_them(): void
    {
        $this->as($this->viewer)->postJson(self::URL, $this->body(['customer_complaints_reported' => true, 'complaints' => [$this->customerComplaint()]]))->assertForbidden();
        $this->assertSame(0, FacilityComplaint::query()->count());
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-complaints')->assertOk();
    }
}
