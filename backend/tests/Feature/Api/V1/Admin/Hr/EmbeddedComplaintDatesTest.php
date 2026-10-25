<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Enums\FacilityItemKey;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\FacilityComplaint;
use App\Models\FacilityInspection;
use App\Models\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Complaints entered INSIDE an inspection take their default response / resolution / feedback dates from the Mogadishu calendar and never from an
 * earlier day. Between 21:00 and 24:00 UTC it is already the next day in Mogadishu: the complaint is reported on the inspection date (that Mogadishu
 * day) and a UTC "now" default would put the response one day BEFORE the report. Dates the user typed are kept; the stand-alone Complaints screen
 * keeps its own UTC defaults. Every test freezes UTC instants on purpose.
 */
class EmbeddedComplaintDatesTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const BEFORE_MIDNIGHT = '2026-10-31 20:59:59'; // 23:59:59 on 31 October in Mogadishu

    private const AFTER_MIDNIGHT = '2026-10-31 21:00:00';  // 00:00:00 on 1 November in Mogadishu, 31 October in UTC

    private Admin $managerAdmin;

    private string $manager;

    private ClientCompany $alpha;

    protected function setUp(): void
    {
        parent::setUp();
        $this->freeze(self::BEFORE_MIDNIGHT);
        $this->alpha = $this->makeCompany('Alpha Hospital');
    }

    private function freeze(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));
        $this->managerAdmin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        foreach (['hr.view', 'hr.manage', 'hr.reports.view', 'hr.inspections.view', 'hr.inspections.manage'] as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $this->managerAdmin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->manager = $this->managerAdmin->createToken('t')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function items(): array
    {
        return array_map(fn (FacilityItemKey $key): array => ['item_key' => $key->value, 'applicable' => true, 'result' => 'satisfactory'], FacilityItemKey::cases());
    }

    /** An inspection form submission with one customer complaint; $complaint overrides/extends the complaint's fields. */
    private function submit(string $inspectedOn, array $complaint = [], array $over = []): TestResponse
    {
        $entry = array_replace([
            'type' => 'customer',
            'details' => 'The toilets were not cleaned',
            'reporter_name' => 'A customer',
            'responsible_person' => 'Site lead',
        ], $complaint);

        return $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', array_replace([
            'client_company_id' => $this->alpha->id,
            'inspector_name' => 'Inspector One',
            'inspected_on' => $inspectedOn,
            'items' => $this->items(),
            'staff_complaints_reported' => false,
            'customer_complaints_reported' => true,
            'complaints' => [$entry],
        ], $over));
    }

    private function complaint(): FacilityComplaint
    {
        return FacilityComplaint::query()->sole();
    }

    /** @return array{reported: string, responded: ?string, resolved: ?string, satisfaction: ?string} */
    private function dates(): array
    {
        $c = $this->complaint();

        return [
            'reported' => $c->reported_on->toDateString(),
            'responded' => $c->responded_on?->toDateString(),
            'resolved' => $c->resolved_on?->toDateString(),
            'satisfaction' => $c->satisfaction_recorded_on?->toDateString(),
        ];
    }

    private const ANSWER = ['response' => 'We will clean them today', 'corrective_action' => 'Extra cleaning visit'];

    // ------------------------------------------------------------------ before midnight in Mogadishu: the existing behaviour

    public function test_before_midnight_in_mogadishu_the_defaults_are_the_same_day(): void
    {
        $this->submit('2026-10-31', [...self::ANSWER, 'status' => 'resolved', 'status_note' => 'Cleaned and confirmed by the customer'])->assertCreated();

        $this->assertSame(['reported' => '2026-10-31', 'responded' => '2026-10-31', 'resolved' => '2026-10-31', 'satisfaction' => null], $this->dates());
    }

    // ------------------------------------------------------------------ after midnight in Mogadishu, still the previous day in UTC

    public function test_a_response_without_a_response_date_is_not_dated_before_the_report(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01', ['response' => 'We will clean them today'])->assertCreated();

        $this->assertSame(['reported' => '2026-11-01', 'responded' => '2026-11-01', 'resolved' => null, 'satisfaction' => null], $this->dates());
        $this->assertSame('open', $this->complaint()->status->value);
    }

    public function test_no_response_means_no_response_date(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01')->assertCreated();

        $this->assertSame(['reported' => '2026-11-01', 'responded' => null, 'resolved' => null, 'satisfaction' => null], $this->dates());
    }

    public function test_answering_and_resolving_without_explicit_dates_use_the_mogadishu_day(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01', [...self::ANSWER, 'status' => 'answered'])->assertCreated();
        $this->assertSame(['reported' => '2026-11-01', 'responded' => '2026-11-01', 'resolved' => null, 'satisfaction' => null], $this->dates());
        $this->assertSame('answered', $this->complaint()->status->value);

        FacilityComplaint::query()->delete();
        FacilityInspection::query()->delete();

        $this->submit('2026-11-01', [...self::ANSWER, 'status' => 'resolved', 'status_note' => 'Cleaned and confirmed by the customer'])->assertCreated();
        $this->assertSame(['reported' => '2026-11-01', 'responded' => '2026-11-01', 'resolved' => '2026-11-01', 'satisfaction' => null], $this->dates());
        $this->assertSame('resolved', $this->complaint()->status->value);
    }

    public function test_documented_feedback_is_not_dated_before_the_report_either(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01', [
            ...self::ANSWER, 'status' => 'resolved', 'status_note' => 'Cleaned and confirmed by the customer',
            'satisfaction' => 'satisfied', 'satisfaction_evidence' => 'The customer said so by phone at the site visit',
        ])->assertCreated();

        $this->assertSame(['reported' => '2026-11-01', 'responded' => '2026-11-01', 'resolved' => '2026-11-01', 'satisfaction' => '2026-11-01'], $this->dates());
    }

    public function test_a_date_time_sent_as_the_report_date_is_read_as_its_calendar_day_for_every_default(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        foreach (['2026-11-01T00:00:00', '2026-11-01T00:00:00Z', '2026-11-01T00:00:00+03:00', '2026-11-01 00:00:00'] as $reported) {
            FacilityComplaint::query()->delete();
            FacilityInspection::query()->delete();

            $this->submit('2026-11-01', [
                ...self::ANSWER, 'reported_on' => $reported, 'status' => 'resolved', 'status_note' => 'Cleaned and confirmed by the customer',
                'satisfaction' => 'satisfied', 'satisfaction_evidence' => 'The customer said so by phone at the site visit',
            ])->assertCreated();

            $this->assertSame(['reported' => '2026-11-01', 'responded' => '2026-11-01', 'resolved' => '2026-11-01', 'satisfaction' => '2026-11-01'], $this->dates(), $reported);
        }
    }

    public function test_the_report_date_still_defaults_to_the_inspection_date(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01', ['response' => 'We will clean them today', 'reported_on' => null])->assertCreated();

        $this->assertSame('2026-11-01', $this->complaint()->reported_on->toDateString());
        $this->assertSame('2026-11-01', $this->complaint()->responded_on->toDateString());
    }

    public function test_an_older_report_date_gets_the_current_mogadishu_day_as_its_default_response_date(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01', ['reported_on' => '2026-10-20', 'response' => 'We will clean them today'])->assertCreated();

        $this->assertSame(['reported' => '2026-10-20', 'responded' => '2026-11-01', 'resolved' => null, 'satisfaction' => null], $this->dates());
    }

    // ------------------------------------------------------------------ what the user typed is kept; impossible chronology is refused

    public function test_dates_typed_by_the_user_are_preserved(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01', [...self::ANSWER, 'reported_on' => '2026-10-29', 'responded_on' => '2026-10-30', 'status' => 'answered'])->assertCreated();

        $this->assertSame(['reported' => '2026-10-29', 'responded' => '2026-10-30', 'resolved' => null, 'satisfaction' => null], $this->dates());
    }

    public function test_a_response_date_before_the_report_date_is_still_refused_and_nothing_is_saved(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $this->submit('2026-11-01', [...self::ANSWER, 'reported_on' => '2026-11-01', 'responded_on' => '2026-10-31'])
            ->assertStatus(422)->assertJsonValidationErrors('complaints.0.responded_on');
        $this->submit('2026-11-01', [...self::ANSWER, 'reported_on' => '2026-11-01', 'follow_up_on' => '2026-10-31'])
            ->assertStatus(422)->assertJsonValidationErrors('complaints.0.follow_up_on');
        $this->submit('2026-11-01', ['reported_on' => '2026-11-02'])->assertStatus(422)->assertJsonValidationErrors('complaints.0.reported_on');
        $this->submit('2026-11-01', [...self::ANSWER, 'responded_on' => '2026-11-02'])->assertStatus(422)->assertJsonValidationErrors('complaints.0.responded_on');

        $this->assertSame(0, FacilityInspection::query()->count());
        $this->assertSame(0, FacilityComplaint::query()->count());
    }

    public function test_one_second_before_midnight_the_next_day_is_still_the_future(): void
    {
        $this->submit('2026-10-31', ['reported_on' => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors('complaints.0.reported_on');
        $this->assertSame(0, FacilityComplaint::query()->count());
    }

    // ------------------------------------------------------------------ the stand-alone Complaints screen is unchanged

    public function test_the_standalone_complaints_screen_keeps_its_utc_rule_and_utc_defaults(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        // The Mogadishu day is still "in the future" for that screen's own rule ...
        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-complaints', [
            'client_company_id' => $this->alpha->id, 'type' => 'customer', 'details' => 'The toilets were not cleaned', 'reported_on' => '2026-11-01',
        ])->assertStatus(422);

        // ... and its defaults are the UTC date of "now", exactly as before.
        $id = (int) $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-complaints', [
            'client_company_id' => $this->alpha->id, 'type' => 'customer', 'details' => 'The toilets were not cleaned', 'reported_on' => '2026-10-31',
            'response' => 'We will clean them today',
        ])->assertCreated()->json('data.id');
        $this->assertSame('2026-10-31', FacilityComplaint::query()->findOrFail($id)->responded_on->toDateString());

        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-complaints/{$id}/status", ['status' => 'resolved', 'note' => 'Cleaned and confirmed by the customer'])->assertOk();
        $this->assertSame('2026-10-31', FacilityComplaint::query()->findOrFail($id)->resolved_on->toDateString(), 'the stand-alone resolution default is still the UTC day');
    }

    // ------------------------------------------------------------------ rollback and audit

    public function test_a_complaint_the_server_refuses_rolls_the_whole_inspection_back(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);
        $outsider = $this->makeEmployee(); // not assigned to the inspected company
        $audits = AuditLog::query()->count();

        $this->submit('2026-11-01', ['type' => 'staff', 'reporter_employee_id' => $outsider->id, 'reporter_name' => null, ...self::ANSWER], ['staff_complaints_reported' => true, 'customer_complaints_reported' => false])
            ->assertStatus(422);

        $this->assertSame(0, FacilityInspection::query()->count());
        $this->assertSame(0, FacilityComplaint::query()->count());
        $this->assertSame($audits, AuditLog::query()->count(), 'no audit entry survives a rolled-back inspection');
    }

    public function test_audit_entries_and_the_status_history_are_still_written(): void
    {
        $this->freeze(self::AFTER_MIDNIGHT);

        $id = (int) $this->submit('2026-11-01', [...self::ANSWER, 'status' => 'resolved', 'status_note' => 'Cleaned and confirmed by the customer'])->assertCreated()->json('data.id');

        $this->assertDatabaseHas('audit_logs', ['entity_type' => FacilityInspection::class, 'entity_id' => $id, 'action' => 'create', 'admin_id' => $this->managerAdmin->id]);
        $complaint = $this->complaint();
        $this->assertDatabaseHas('audit_logs', ['entity_type' => FacilityComplaint::class, 'entity_id' => $complaint->id, 'action' => 'create', 'admin_id' => $this->managerAdmin->id]);
        $this->assertSame($id, $complaint->facility_inspection_id);
        $this->assertTrue($complaint->events()->where('event', 'status_changed')->where('to_status', 'resolved')->exists());
        $this->assertTrue(FacilityInspection::query()->findOrFail($id)->customer_complaints_reported);
    }
}
