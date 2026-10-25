<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Enums\FacilityDeepCleaningState;
use App\Enums\FacilityItemKey;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\FacilityDeepCleaning;
use App\Models\FacilityInspection;
use App\Models\Permission;
use App\Support\Hr\Facility\FacilityDeepCleaningBuilder;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Monthly Deep Cleaning inside the facility inspection: it is recorded only when someone explicitly says so, belongs to the inspection that
 * recorded it, keeps its own completion date, is never silently overwritten by an ordinary edit, cannot be counted twice, and the monthly overview
 * is computed from those records only (calendar months, four non-overlapping states, gaps stay visible). Synthetic data only.
 */
class FacilityDeepCleaningTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const TODAY = '2026-10-21';

    private Admin $managerAdmin;

    private string $manager;

    private string $secondManager;

    private string $viewer;

    private ClientCompany $alpha;

    private ClientCompany $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo(CarbonImmutable::parse(self::TODAY.' 10:00:00', 'UTC'));

        $perms = ['hr.view', 'hr.manage', 'hr.reports.view', 'hr.inspections.view', 'hr.inspections.manage'];
        $this->managerAdmin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        $this->grant($this->managerAdmin, $perms);
        $this->manager = $this->managerAdmin->createToken('t')->plainTextToken;
        $this->secondManager = $this->tokenForRoleWith(AdminRole::HrManager, $perms);
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.reports.view', 'hr.inspections.view']);

        // The facilities existed long before the months under test (the overview only lists facilities that existed by the end of the month).
        $this->alpha = $this->makeCompany('Alpha Hospital');
        $this->beta = $this->makeCompany('Beta School');
        ClientCompany::query()->update(['created_at' => '2026-01-01 08:00:00']);
    }

    /** @param  list<string>  $keys */
    private function grant(Admin $admin, array $keys): void
    {
        foreach ($keys as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @return list<array<string, mixed>> */
    private function items(): array
    {
        return array_map(fn (FacilityItemKey $key): array => ['item_key' => $key->value, 'applicable' => true, 'result' => 'satisfactory'], FacilityItemKey::cases());
    }

    private function body(ClientCompany $company, array $over = []): array
    {
        return array_replace([
            'client_company_id' => $company->id,
            'inspector_name' => 'Inspector One',
            'inspected_on' => '2026-10-10',
            'general_notes' => 'Routine visit',
            'items' => $this->items(),
            'staff_complaints_reported' => false,
            'customer_complaints_reported' => false,
        ], $over);
    }

    private function inspect(ClientCompany $company, array $over = []): int
    {
        return (int) $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($company, $over))->assertCreated()->json('data.id');
    }

    /** An inspection that explicitly records a Deep Cleaning completed on $on. */
    private function cleaned(ClientCompany $company, string $on, array $deep = []): int
    {
        return $this->inspect($company, ['deep_cleaning' => array_replace(['completed' => true, 'completed_on' => $on], $deep)]);
    }

    /** @return array<string, mixed> the whole response: data = rows, meta = counts/month */
    private function overview(string $month, array $query = [], ?string $token = null): array
    {
        return $this->as($token ?? $this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?'.http_build_query(['month' => $month, ...$query]))->assertOk()->json();
    }

    private function row(array $overview, ClientCompany $company): ?array
    {
        return collect($overview['data'])->firstWhere('client_company_id', $company->id);
    }

    private function stateOf(string $month, ClientCompany $company, array $query = []): string
    {
        return $this->row($this->overview($month, $query), $company)['state'];
    }

    // ------------------------------------------------------------------ the four states

    public function test_a_facility_with_a_completion_in_the_selected_month_is_completed(): void
    {
        $this->cleaned($this->alpha, '2026-10-05');

        $overview = $this->overview('2026-10');
        $row = $this->row($overview, $this->alpha);

        $this->assertSame('completed', $row['state']);
        $this->assertSame('Completed this month', $row['state_label']);
        $this->assertSame('2026-10-05', $row['completed_in_month_on']);
        $this->assertSame('2026-10-05', $row['last_completed_on']);
        $this->assertSame(0, $row['months_without']);
    }

    public function test_a_facility_without_a_completion_in_the_month_but_with_the_previous_month_is_not_completed_yet(): void
    {
        $this->cleaned($this->alpha, '2026-09-15');

        $row = $this->row($this->overview('2026-10'), $this->alpha);

        $this->assertSame('not_completed', $row['state']);
        $this->assertSame(1, $row['months_without']);
        $this->assertSame('2026-09-15', $row['last_completed_on'], 'the last recorded Deep Cleaning is still shown');
        $this->assertNull($row['completed_in_month_on']);
    }

    public function test_a_facility_that_never_had_a_recorded_deep_cleaning_has_no_history_and_is_not_confused_with_overdue(): void
    {
        $this->inspect($this->alpha); // a routine inspection says nothing about Deep Cleaning

        $row = $this->row($this->overview('2026-10'), $this->alpha);

        $this->assertSame('no_history', $row['state']);
        $this->assertNull($row['last_completed_on']);
        $this->assertNull($row['completed_in_month_on']);
        $this->assertSame(0, FacilityDeepCleaning::query()->count(), 'an inspection never records a completion by itself');
    }

    public function test_several_consecutive_months_without_a_completion_are_overdue_and_the_threshold_is_exact(): void
    {
        $this->cleaned($this->alpha, '2026-07-20');

        $this->assertSame(3, $this->row($this->overview('2026-10'), $this->alpha)['months_without'], 'August, September and October have none');
        $this->assertSame('overdue_multiple', $this->stateOf('2026-10', $this->alpha));
        $this->assertSame('overdue_multiple', $this->stateOf('2026-09', $this->alpha), 'August and September: two consecutive months, the documented threshold');
        $this->assertSame('not_completed', $this->stateOf('2026-08', $this->alpha), 'only August itself is missing');
        $this->assertSame('completed', $this->stateOf('2026-07', $this->alpha));
        $this->assertSame(FacilityDeepCleaningBuilder::OVERDUE_AFTER_MISSED_MONTHS, $this->overview('2026-10')['meta']['overdue_after_months']);
    }

    public function test_a_completion_this_month_after_missed_months_is_completed_while_the_earlier_gaps_stay_visible(): void
    {
        $this->cleaned($this->alpha, '2026-07-20');
        $this->cleaned($this->alpha, '2026-10-03');

        $this->assertSame('completed', $this->stateOf('2026-10', $this->alpha), 'the current month is satisfied');
        $this->assertSame('overdue_multiple', $this->stateOf('2026-09', $this->alpha), 'September, looked at later, still shows the gap');
        $this->assertSame('not_completed', $this->stateOf('2026-08', $this->alpha));

        $strip = collect($this->row($this->overview('2026-10'), $this->alpha)['history'])->pluck('completed', 'month')->all();
        $this->assertSame(['2026-05' => false, '2026-06' => false, '2026-07' => true, '2026-08' => false, '2026-09' => false, '2026-10' => true], $strip);
    }

    public function test_calendar_month_boundaries_are_exact(): void
    {
        $this->cleaned($this->alpha, '2026-09-30');   // last day of September only
        $this->cleaned($this->beta, '2026-10-01');    // first day of October only

        $this->assertSame('completed', $this->stateOf('2026-09', $this->alpha));
        $this->assertSame('not_completed', $this->stateOf('2026-10', $this->alpha), '30 September does not satisfy October');
        $this->assertSame('no_history', $this->stateOf('2026-09', $this->beta), '1 October is not part of September');
        $this->assertSame('completed', $this->stateOf('2026-10', $this->beta));
        $this->assertSame('2026-10-01', $this->row($this->overview('2026-10'), $this->beta)['completed_in_month_on']);
    }

    public function test_the_state_rule_is_pure_and_never_overlaps(): void
    {
        $builder = new FacilityDeepCleaningBuilder;
        $october = CarbonImmutable::parse('2026-10-01');
        $at = fn (string $d) => CarbonImmutable::parse($d);

        $this->assertSame(FacilityDeepCleaningState::Completed, $builder->state($october, true, $at('2026-10-02'))[0]);
        $this->assertSame(FacilityDeepCleaningState::Completed, $builder->state($october, true, null)[0], 'a completion in the month wins over anything else');
        $this->assertSame(FacilityDeepCleaningState::NoHistory, $builder->state($october, false, null)[0]);
        $this->assertSame([FacilityDeepCleaningState::NotCompleted, 1], $builder->state($october, false, $at('2026-09-30')));
        $this->assertSame([FacilityDeepCleaningState::OverdueMultiple, 2], $builder->state($october, false, $at('2026-08-31')));
        $this->assertSame([FacilityDeepCleaningState::OverdueMultiple, 13], $builder->state($october, false, $at('2025-09-01')), 'across a year boundary');
        $this->assertSame([FacilityDeepCleaningState::NotCompleted, 1], $builder->state(CarbonImmutable::parse('2026-01-01'), false, $at('2025-12-31')), 'December to January is one month');
    }

    // ------------------------------------------------------------------ recording rules

    public function test_a_routine_inspection_records_no_deep_cleaning_in_any_form(): void
    {
        $plain = $this->inspect($this->alpha);
        $explicitNo = $this->inspect($this->beta, ['deep_cleaning' => ['completed' => false]]);

        $this->assertSame(0, FacilityDeepCleaning::query()->count());
        foreach ([$plain, $explicitNo] as $id) {
            $detail = $this->as($this->viewer)->getJson("/api/v1/admin/hr/facility-inspections/{$id}")->assertOk()->json('data');
            $this->assertNull($detail['deep_cleaning']);
            $this->assertNull($detail['deep_cleaning_completed_on']);
        }
        $this->assertSame('no_history', $this->stateOf('2026-10', $this->alpha));
    }

    public function test_completion_needs_its_own_valid_date_and_a_future_date_is_rejected(): void
    {
        $post = fn (array $deep) => $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, ['deep_cleaning' => $deep]));

        $post(['completed' => true])->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $post(['completed' => true, 'completed_on' => '2026-10-22'])->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $post(['completed' => true, 'completed_on' => '2027-01-01'])->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $post(['completed' => true, 'completed_on' => 'not a date'])->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $post(['completed' => true, 'completed_on' => '1999-05-01'])->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $post(['completed' => false, 'completed_on' => '2026-10-05'])->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $post(['completed_on' => '2026-10-05'])->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed');

        $this->assertSame(0, FacilityInspection::query()->count(), 'a refused request saves nothing, not even the inspection');
        $post(['completed' => true, 'completed_on' => self::TODAY])->assertCreated();
    }

    public function test_completion_keeps_its_date_notes_and_who_recorded_it_apart_from_the_inspection_dates(): void
    {
        $id = $this->cleaned($this->alpha, '2026-10-02', ['notes' => 'Whole building, all floors']);

        $row = FacilityDeepCleaning::query()->where('facility_inspection_id', $id)->sole();
        $this->assertSame('2026-10-02', $row->completed_on->toDateString());
        $this->assertSame('2026-10-10', FacilityInspection::query()->findOrFail($id)->inspected_on->toDateString(), 'the inspection date is a different thing');
        $this->assertSame($this->alpha->id, $row->client_company_id);
        $this->assertSame($this->managerAdmin->id, $row->recorded_by);
        $this->assertNull($row->changed_by);

        $detail = $this->as($this->viewer)->getJson("/api/v1/admin/hr/facility-inspections/{$id}")->assertOk()->json('data');
        $this->assertSame('2026-10-02', $detail['deep_cleaning']['completed_on']);
        $this->assertSame('Whole building, all floors', $detail['deep_cleaning']['notes']);
        $this->assertSame($this->managerAdmin->full_name, $detail['deep_cleaning']['recorded_by']);
        $this->assertFalse($detail['deep_cleaning']['withdrawn']);
        $this->assertSame('2026-10-02', $detail['deep_cleaning_completed_on']);

        $log = AuditLog::query()->where('entity_type', FacilityDeepCleaning::class)->where('action', 'create')->sole();
        $this->assertSame($this->managerAdmin->id, $log->admin_id);
        $this->assertSame('2026-10-02', $log->metadata['completed_on']);
    }

    public function test_editing_an_inspection_without_the_deep_cleaning_section_leaves_the_record_and_its_history_alone(): void
    {
        $id = $this->cleaned($this->alpha, '2026-10-02', ['notes' => 'Kept']);
        $audits = AuditLog::query()->where('entity_type', FacilityDeepCleaning::class)->count();

        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", [
            'general_notes' => 'A corrected note',
            'items' => collect($this->items())->map(fn ($i) => $i['item_key'] === 'windows' ? ['item_key' => 'windows', 'applicable' => true, 'result' => 'unsatisfactory', 'issue_description' => 'Dirty', 'corrective_action' => 'Wash'] : $i)->all(),
        ])->assertOk();

        $row = FacilityDeepCleaning::query()->where('facility_inspection_id', $id)->sole();
        $this->assertSame('2026-10-02', $row->completed_on->toDateString());
        $this->assertSame('Kept', $row->notes);
        $this->assertNull($row->voided_at);
        $this->assertNull($row->changed_by);
        $this->assertSame($audits, AuditLog::query()->where('entity_type', FacilityDeepCleaning::class)->count(), 'no Deep Cleaning audit entry was written or lost');
        $this->assertSame('completed', $this->stateOf('2026-10', $this->alpha));
    }

    public function test_changing_a_recorded_completion_needs_a_reason_and_keeps_before_and_after_in_the_audit_log(): void
    {
        $id = $this->cleaned($this->alpha, '2026-10-02');
        $put = fn (array $deep, ?string $token = null) => $this->as($token ?? $this->secondManager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['deep_cleaning' => $deep]);

        $put(['completed' => true, 'completed_on' => '2026-10-04'])->assertStatus(422)->assertJsonPath('error_code', 'DEEP_CLEANING_CHANGE_REASON_REQUIRED');
        $put(['completed' => true, 'completed_on' => '2026-10-04', 'change_reason' => 'no'])->assertStatus(422);
        $this->assertSame('2026-10-02', FacilityDeepCleaning::query()->sole()->completed_on->toDateString(), 'refused changes leave the record as it was');

        $put(['completed' => true, 'completed_on' => '2026-10-04', 'change_reason' => 'The date was entered wrongly'])->assertOk();

        $row = FacilityDeepCleaning::query()->sole();
        $this->assertSame('2026-10-04', $row->completed_on->toDateString());
        $this->assertSame($this->managerAdmin->id, $row->recorded_by, 'who first recorded it is kept');
        $this->assertNotNull($row->changed_by);
        $this->assertNotSame($row->recorded_by, $row->changed_by);
        $this->assertSame('The date was entered wrongly', $row->change_reason);

        $log = AuditLog::query()->where('entity_type', FacilityDeepCleaning::class)->where('action', 'update')->sole();
        $this->assertSame('2026-10-02', $log->metadata['before']['completed_on']);
        $this->assertSame('2026-10-04', $log->metadata['after']['completed_on']);
        $this->assertSame('The date was entered wrongly', $log->metadata['reason']);
    }

    public function test_repeating_the_same_completion_changes_nothing_and_needs_no_reason(): void
    {
        $id = $this->cleaned($this->alpha, '2026-10-02', ['notes' => 'Same']);
        $before = AuditLog::query()->where('entity_type', FacilityDeepCleaning::class)->count();

        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['deep_cleaning' => ['completed' => true, 'completed_on' => '2026-10-02', 'notes' => 'Same']])->assertOk();

        $this->assertSame(1, FacilityDeepCleaning::query()->count());
        $this->assertNull(FacilityDeepCleaning::query()->sole()->changed_by);
        $this->assertSame($before, AuditLog::query()->where('entity_type', FacilityDeepCleaning::class)->count());
    }

    public function test_a_recorded_completion_is_withdrawn_with_a_reason_kept_as_history_and_no_longer_counted(): void
    {
        $id = $this->cleaned($this->alpha, '2026-10-02');
        $put = fn (array $deep) => $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['deep_cleaning' => $deep]);

        $put(['completed' => false])->assertStatus(422)->assertJsonPath('error_code', 'DEEP_CLEANING_CHANGE_REASON_REQUIRED');
        $this->assertSame('completed', $this->stateOf('2026-10', $this->alpha));

        $put(['completed' => false, 'change_reason' => 'It was entered for the wrong company'])->assertOk();

        $row = FacilityDeepCleaning::query()->sole();
        $this->assertNotNull($row->voided_at, 'kept, not deleted');
        $this->assertSame('It was entered for the wrong company', $row->void_reason);
        $this->assertSame('no_history', $this->stateOf('2026-10', $this->alpha), 'a withdrawn completion counts nowhere');
        $this->assertNull($this->row($this->overview('2026-10'), $this->alpha)['last_completed_on']);

        // Recording it again is a deliberate act with a reason, and it can reuse the same day.
        $put(['completed' => true, 'completed_on' => '2026-10-02'])->assertStatus(422);
        $put(['completed' => true, 'completed_on' => '2026-10-02', 'change_reason' => 'It was right after all'])->assertOk();
        $this->assertNull(FacilityDeepCleaning::query()->sole()->voided_at);
        $this->assertSame('completed', $this->stateOf('2026-10', $this->alpha));
    }

    public function test_voiding_the_inspection_withdraws_its_completion_so_it_stops_counting(): void
    {
        $id = $this->cleaned($this->alpha, '2026-10-02');
        $this->assertSame('completed', $this->stateOf('2026-10', $this->alpha));

        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-inspections/{$id}/void", ['reason' => 'Recorded for the wrong site'])->assertOk();

        $row = FacilityDeepCleaning::query()->sole();
        $this->assertNotNull($row->voided_at);
        $this->assertStringContainsString('Recorded for the wrong site', (string) $row->void_reason);
        $this->assertSame('no_history', $this->stateOf('2026-10', $this->alpha));
        // The same day can now be recorded correctly on another inspection.
        $this->cleaned($this->alpha, '2026-10-02');
        $this->assertSame('completed', $this->stateOf('2026-10', $this->alpha));
    }

    // ------------------------------------------------------------------ duplicates

    public function test_the_same_cleaning_cannot_be_recorded_twice_by_a_repeated_submission(): void
    {
        $first = $this->cleaned($this->alpha, '2026-10-02');

        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, ['deep_cleaning' => ['completed' => true, 'completed_on' => '2026-10-02']]))
            ->assertStatus(422)->assertJsonPath('error_code', 'DEEP_CLEANING_ALREADY_RECORDED');

        $this->assertSame(1, FacilityDeepCleaning::query()->count());
        $this->assertSame(1, FacilityInspection::query()->count(), 'the refused submission left no second inspection behind');
        $this->assertSame($first, FacilityDeepCleaning::query()->sole()->facility_inspection_id);

        // A different day for the same company, and the same day for another company, are different cleanings.
        $this->cleaned($this->alpha, '2026-10-03');
        $this->cleaned($this->beta, '2026-10-02');
        $this->assertSame(3, FacilityDeepCleaning::query()->count());
    }

    public function test_the_database_itself_refuses_a_second_counted_row_for_the_same_inspection_or_the_same_company_and_day(): void
    {
        $id = $this->cleaned($this->alpha, '2026-10-02');
        $other = $this->inspect($this->alpha);
        $row = fn (int $inspection, string $day, ?string $voidedAt = null) => (bool) FacilityDeepCleaning::query()->create([
            'facility_inspection_id' => $inspection, 'client_company_id' => $this->alpha->id, 'completed_on' => $day, 'voided_at' => $voidedAt,
        ]);

        $this->assertQueryRefused(fn () => $row($id, '2026-10-09'), 'one record per inspection');
        $this->assertQueryRefused(fn () => $row($other, '2026-10-02'), 'one counted record per company and day');
        $this->assertTrue($row($other, '2026-10-02', '2026-10-20 10:00:00'), 'a withdrawn row never blocks a correct one');
    }

    private function assertQueryRefused(callable $write, string $why): void
    {
        try {
            DB::transaction($write);
            $this->fail("The database accepted a duplicate ({$why}).");
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
    }

    // ------------------------------------------------------------------ authorization

    public function test_only_inspection_managers_can_record_and_only_inspection_viewers_can_read(): void
    {
        $none = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $id = $this->inspect($this->alpha);

        $this->as($this->viewer)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, ['deep_cleaning' => ['completed' => true, 'completed_on' => '2026-10-02']]))->assertForbidden();
        $this->as($this->viewer)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['deep_cleaning' => ['completed' => true, 'completed_on' => '2026-10-02']])->assertForbidden();
        $this->as($none)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2026-10')->assertForbidden();
        $this->as($this->noPermissionToken())->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning')->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning')->assertUnauthorized();
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning')->assertOk();
        $this->assertSame(0, FacilityDeepCleaning::query()->count());
    }

    public function test_a_completion_always_belongs_to_the_company_of_its_inspection_whatever_the_request_says(): void
    {
        $id = $this->inspect($this->alpha);

        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", [
            'client_company_id' => $this->beta->id,
            'deep_cleaning' => ['completed' => true, 'completed_on' => '2026-10-02', 'client_company_id' => $this->beta->id],
        ])->assertOk();

        $this->assertSame($this->alpha->id, FacilityDeepCleaning::query()->sole()->client_company_id);
        $this->assertSame('completed', $this->stateOf('2026-10', $this->alpha));
        $this->assertSame('no_history', $this->stateOf('2026-10', $this->beta));
    }

    // ------------------------------------------------------------------ the overview: counts, filters, facilities

    public function test_summary_counts_match_the_rows_and_the_filters_narrow_them(): void
    {
        $gamma = $this->makeCompany('Gamma Guri', 'guri');
        $delta = $this->makeCompany('Delta Clinic');
        ClientCompany::query()->update(['created_at' => '2026-01-01 08:00:00']);

        $this->cleaned($this->alpha, '2026-10-05');   // completed
        $this->cleaned($this->beta, '2026-09-12');    // not completed (1 month)
        $this->cleaned($delta, '2026-06-30');         // overdue (3 months: Jul, Aug, Sep... Oct = 4)
        // gamma: never                                // no history

        $overview = $this->overview('2026-10');
        $counts = $overview['meta']['counts'];

        $this->assertSame(['completed' => 1, 'not_completed' => 1, 'overdue_multiple' => 1, 'no_history' => 1, 'total' => 4], $counts);
        $this->assertCount(4, $overview['data']);
        $this->assertSame(['overdue_multiple', 'no_history', 'not_completed', 'completed'], array_column($overview['data'], 'state'), 'facilities needing follow-up come first');
        $this->assertSame(4, $this->row($overview, $delta)['months_without']);
        $this->assertSame('2026-10', $overview['meta']['month']['value']);
        $this->assertSame(['2026-10-01', '2026-10-31'], [$overview['meta']['month']['from'], $overview['meta']['month']['to']]);

        $this->assertSame([$delta->id], array_column($this->overview('2026-10', ['state' => 'overdue_multiple'])['data'], 'client_company_id'));
        $this->assertSame([$gamma->id], array_column($this->overview('2026-10', ['category' => 'guri'])['data'], 'client_company_id'));
        $this->assertSame([$this->beta->id], array_column($this->overview('2026-10', ['search' => 'beta'])['data'], 'client_company_id'));
        $this->assertSame([$this->alpha->id], array_column($this->overview('2026-10', ['client_company_id' => $this->alpha->id])['data'], 'client_company_id'));
        $this->assertSame(4, $this->overview('2026-10', ['state' => 'completed'])['meta']['counts']['total'], 'the totals describe the whole month, whatever tab is open');
        $this->assertSame([], $this->overview('2026-10', ['search' => 'no such facility'])['data']);
    }

    public function test_the_month_defaults_to_the_current_one_and_a_bad_or_future_month_is_refused(): void
    {
        $this->cleaned($this->alpha, '2026-10-05');

        $default = $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning')->assertOk()->json();
        $this->assertSame('2026-10', $default['meta']['month']['value']);
        $this->assertTrue($default['meta']['month']['is_current']);
        $this->assertFalse($this->overview('2026-09')['meta']['month']['is_current']);

        foreach (['2026-11', '2027-01', '2026-13', 'October', '2026-1', '2026-10-05'] as $bad) {
            $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month='.$bad)->assertStatus(422);
        }
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?state=nonsense')->assertStatus(422);
    }

    public function test_inactive_facilities_are_flagged_and_never_hidden_silently(): void
    {
        $closed = $this->makeCompany('Closed Clinic');
        $closedQuiet = $this->makeCompany('Closed Warehouse');
        ClientCompany::query()->update(['created_at' => '2026-01-01 08:00:00']);
        $this->cleaned($closed, '2026-10-04');
        ClientCompany::query()->whereIn('id', [$closed->id, $closedQuiet->id])->update(['status' => 'inactive']);

        $default = $this->overview('2026-10');
        $this->assertSame('inactive', $this->row($default, $closed)['facility_status'], 'completed work stays visible, flagged inactive');
        $this->assertNull($this->row($default, $closedQuiet), 'an inactive facility with nothing recorded is left out of the follow-up list...');
        $this->assertSame(1, $default['meta']['inactive_hidden'], '...but the omission is reported, not silent');
        $this->assertSame(3, $default['meta']['counts']['total']);

        $all = $this->overview('2026-10', ['include_inactive' => 1]);
        $this->assertSame('no_history', $this->row($all, $closedQuiet)['state']);
        $this->assertSame(0, $all['meta']['inactive_hidden']);
        $this->assertSame(4, $all['meta']['counts']['total']);
    }

    public function test_a_facility_registered_after_the_month_is_not_expected_to_have_been_cleaned_in_it(): void
    {
        $newcomer = $this->makeCompany('Newcomer Offices');
        ClientCompany::query()->whereKey($newcomer->id)->update(['created_at' => '2026-10-05 09:00:00']);

        $this->assertNull($this->row($this->overview('2026-09'), $newcomer), 'it did not exist in September');
        $this->assertSame('no_history', $this->row($this->overview('2026-10'), $newcomer)['state']);
    }

    public function test_the_overview_reads_only_recorded_completions_and_never_writes(): void
    {
        $this->cleaned($this->alpha, '2026-09-12');
        $snapshot = fn () => [FacilityDeepCleaning::query()->orderBy('id')->get()->toJson(), FacilityInspection::query()->count(), AuditLog::query()->count()];
        $before = $snapshot();

        $this->overview('2026-10');
        $this->overview('2026-08');

        $this->assertSame($before, $snapshot());
    }

    // ------------------------------------------------------------------ data compatibility

    public function test_existing_inspections_keep_working_and_the_migration_is_reversible(): void
    {
        $id = $this->inspect($this->alpha);
        $path = base_path('database/migrations/2026_10_25_100000_create_facility_deep_cleanings_table.php');

        $this->assertTrue(Schema::hasTable('facility_deep_cleanings'));
        $this->assertSame(['id', 'facility_inspection_id', 'client_company_id', 'completed_on', 'notes', 'recorded_by', 'changed_by', 'change_reason', 'voided_at', 'voided_by', 'void_reason', 'created_at', 'updated_at'], Schema::getColumnListing('facility_deep_cleanings'));

        (require $path)->down();
        $this->assertFalse(Schema::hasTable('facility_deep_cleanings'));
        $this->assertSame(1, FacilityInspection::query()->count(), 'rolling back touches no inspection');
        $this->assertSame(12, FacilityInspection::query()->findOrFail($id)->items()->count());

        (require $path)->up();
        $this->assertTrue(Schema::hasTable('facility_deep_cleanings'));
        $this->assertSame(1, FacilityInspection::query()->count());

        // An inspection saved before the feature existed is read, listed and edited exactly as before.
        $detail = $this->as($this->viewer)->getJson("/api/v1/admin/hr/facility-inspections/{$id}")->assertOk()->json('data');
        $this->assertNull($detail['deep_cleaning']);
        $this->assertCount(12, $detail['items']);
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections')->assertOk()->assertJsonPath('data.0.deep_cleaning_completed_on', null);
        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['general_notes' => 'Edited'])->assertOk();
    }

    public function test_the_inspection_checklist_is_still_exactly_twelve_items(): void
    {
        $items = $this->items();
        $items[] = ['item_key' => 'deep_cleaning', 'applicable' => true, 'result' => 'satisfactory'];

        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($this->alpha, ['items' => $items]))->assertStatus(422);
        $this->assertCount(12, FacilityItemKey::cases());
    }
}
