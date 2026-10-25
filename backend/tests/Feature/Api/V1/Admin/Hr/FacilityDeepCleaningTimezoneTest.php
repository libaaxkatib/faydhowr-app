<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Enums\FacilityItemKey;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\FacilityDeepCleaning;
use App\Models\FacilityInspection;
use App\Models\Permission;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Deep Cleaning reads its calendar in Africa/Mogadishu (UTC+3) while the application stays on UTC. Every test freezes a UTC instant on purpose
 * (the boundary between the two calendars is 21:00 UTC), so none of it can pass only because the machine happens to run on UTC or Mogadishu time.
 * Dates are plain calendar days: they must come back on the day they were recorded, and historical inspection dates must not move.
 */
class FacilityDeepCleaningTimezoneTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const PERMS = ['hr.view', 'hr.manage', 'hr.reports.view', 'hr.inspections.view', 'hr.inspections.manage'];

    private Admin $managerAdmin;

    private string $manager;

    private string $viewer;

    private ClientCompany $alpha;

    private ClientCompany $beta;

    private string $originalZone;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalZone = date_default_timezone_get();
        $this->freeze('2026-10-21 10:00:00');
        $this->alpha = $this->makeCompany('Alpha Hospital');
        $this->beta = $this->makeCompany('Beta School');
        ClientCompany::query()->update(['created_at' => '2026-01-01 08:00:00']);
    }

    protected function tearDown(): void
    {
        date_default_timezone_set($this->originalZone);
        parent::tearDown();
    }

    /** Freezes a UTC instant and issues fresh tokens at that instant (tokens expire after 30 days, and some tests jump months). */
    private function freeze(string $utc): void
    {
        $this->travelTo(CarbonImmutable::parse($utc, 'UTC'));

        $this->managerAdmin = Admin::factory()->create(['role' => AdminRole::HrManager]);
        foreach (self::PERMS as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $this->managerAdmin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }
        $this->manager = $this->managerAdmin->createToken('t')->plainTextToken;
        $this->viewer = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.reports.view', 'hr.inspections.view']);
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

    private function submit(ClientCompany $company, array $over = []): TestResponse
    {
        return $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-inspections', $this->body($company, $over));
    }

    /** An inspection (dated $inspectedOn) that records a Deep Cleaning completed on $on. */
    private function cleaned(ClientCompany $company, string $on, string $inspectedOn = '2026-10-10'): int
    {
        return (int) $this->submit($company, ['inspected_on' => $inspectedOn, 'deep_cleaning' => ['completed' => true, 'completed_on' => $on]])->assertCreated()->json('data.id');
    }

    /** @return array<string, mixed> */
    private function overview(array $query = []): array
    {
        return $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?'.http_build_query($query))->assertOk()->json();
    }

    private function row(array $overview, ClientCompany $company): ?array
    {
        return collect($overview['data'])->firstWhere('client_company_id', $company->id);
    }

    private function rawDay(string $table, string $column, int $id): string
    {
        return substr((string) DB::table($table)->where('id', $id)->value($column), 0, 10);
    }

    // ------------------------------------------------------------------ the clock at the boundary

    /** @return array<string, array{string, string, string}> */
    public static function boundaries(): array
    {
        return [
            'last second of October' => ['2026-10-31 20:59:59', '2026-10', '2026-10-31'],
            'first second of November' => ['2026-10-31 21:00:00', '2026-11', '2026-11-01'],
            '03:00 on 1 November' => ['2026-11-01 00:00:00', '2026-11', '2026-11-01'],
            'last second of 2026' => ['2026-12-31 20:59:59', '2026-12', '2026-12-31'],
            'first second of 2027' => ['2026-12-31 21:00:00', '2027-01', '2027-01-01'],
            'last second of leap-year February' => ['2028-02-29 20:59:59', '2028-02', '2028-02-29'],
            'first second after leap-year February' => ['2028-02-29 21:00:00', '2028-03', '2028-03-01'],
        ];
    }

    #[DataProvider('boundaries')]
    public function test_the_default_month_and_today_are_the_mogadishu_ones(string $utc, string $month, string $today): void
    {
        $this->freeze($utc);

        $overview = $this->overview();

        $this->assertSame($month, $overview['meta']['month']['value'], 'the month the overview opens on');
        $this->assertSame($month, $overview['meta']['current_month']);
        $this->assertSame($today, $overview['meta']['today']);
        $this->assertSame('Africa/Mogadishu', $overview['meta']['time_zone']);
        $this->assertTrue($overview['meta']['month']['is_current']);
    }

    public function test_the_result_does_not_depend_on_the_servers_default_timezone(): void
    {
        $this->freeze('2026-10-31 21:00:00');

        foreach (['UTC', 'America/New_York', 'Pacific/Auckland', 'Asia/Tokyo'] as $zone) {
            date_default_timezone_set($zone);

            $overview = $this->overview();
            $this->assertSame('2026-11', $overview['meta']['month']['value'], $zone);
            $this->assertSame('2026-11-01', $overview['meta']['today'], $zone);
        }
    }

    // ------------------------------------------------------------------ validation of "today"

    public function test_a_valid_mogadishu_today_is_accepted_and_a_genuinely_future_date_is_refused(): void
    {
        $this->freeze('2026-10-31 21:00:00'); // 00:00 on 1 November in Mogadishu, still 31 October in UTC

        $this->submit($this->alpha, ['inspected_on' => '2026-11-02', 'deep_cleaning' => ['completed' => true, 'completed_on' => '2026-11-01']])
            ->assertStatus(422)->assertJsonValidationErrors('inspected_on');
        $this->submit($this->alpha, ['inspected_on' => '2026-11-01', 'deep_cleaning' => ['completed' => true, 'completed_on' => '2026-11-02']])
            ->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $this->assertSame(0, FacilityInspection::query()->count(), 'refused requests save nothing');

        $id = $this->submit($this->alpha, ['inspected_on' => '2026-11-01', 'deep_cleaning' => ['completed' => true, 'completed_on' => '2026-11-01']])->assertCreated()->json('data.id');
        $this->assertSame('2026-11-01', $this->rawDay('facility_inspections', 'inspected_on', $id));
        $this->assertSame('2026-11-01', FacilityDeepCleaning::query()->sole()->completed_on->toDateString());
    }

    public function test_one_second_earlier_the_next_day_is_still_the_future(): void
    {
        $this->freeze('2026-10-31 20:59:59'); // 23:59:59 on 31 October in Mogadishu

        $this->submit($this->alpha, ['inspected_on' => '2026-10-31', 'deep_cleaning' => ['completed' => true, 'completed_on' => '2026-11-01']])
            ->assertStatus(422)->assertJsonValidationErrors('deep_cleaning.completed_on');
        $this->submit($this->alpha, ['inspected_on' => '2026-11-01'])->assertStatus(422)->assertJsonValidationErrors('inspected_on');
        $this->submit($this->alpha, ['inspected_on' => '2026-10-31', 'deep_cleaning' => ['completed' => true, 'completed_on' => '2026-10-31']])->assertCreated();
    }

    public function test_editing_an_inspection_accepts_the_mogadishu_today_too(): void
    {
        $this->freeze('2026-10-21 10:00:00');
        $id = (int) $this->submit($this->alpha)->assertCreated()->json('data.id');

        $this->freeze('2026-10-31 21:30:00');
        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['inspected_on' => '2026-11-01'])->assertOk();
        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['inspected_on' => '2026-11-02'])->assertStatus(422);
        $this->assertSame('2026-11-01', $this->rawDay('facility_inspections', 'inspected_on', $id));
    }

    public function test_a_complaint_entered_inside_the_inspection_follows_the_same_today_but_the_complaints_screen_is_unchanged(): void
    {
        $this->freeze('2026-10-31 21:00:00');

        $this->submit($this->alpha, [
            'inspected_on' => '2026-11-01',
            'customer_complaints_reported' => true,
            'complaints' => [['type' => 'customer', 'details' => 'The toilets were not cleaned', 'reporter_name' => 'A customer', 'responsible_person' => 'Site lead', 'reported_on' => '2026-11-01']],
        ])->assertCreated();

        // Not part of the approved scope: the stand-alone Complaints screen keeps its existing (UTC) rule, so the same date is still refused there.
        $this->as($this->manager)->postJson('/api/v1/admin/hr/facility-complaints', [
            'client_company_id' => $this->alpha->id, 'type' => 'customer', 'details' => 'The toilets were not cleaned', 'reported_on' => '2026-11-01',
        ])->assertStatus(422);
        // ... and so does the general inspection board's "today".
        $this->assertSame('2026-10-31', $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/status')->assertOk()->json('meta.today'));
    }

    // ------------------------------------------------------------------ the selected month

    public function test_the_next_mogadishu_month_is_never_accepted_as_the_current_one(): void
    {
        $this->freeze('2026-10-31 20:59:59');
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2026-11')->assertStatus(422);
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2026-10')->assertOk();

        $this->freeze('2026-10-31 21:00:00');
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2026-11')->assertOk()->assertJsonPath('meta.month.value', '2026-11');
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2026-10')->assertOk()->assertJsonPath('meta.month.is_current', false);
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2026-12')->assertStatus(422);

        $this->freeze('2026-12-31 21:00:00');
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2027-01')->assertOk();
        $this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/deep-cleaning?month=2027-02')->assertStatus(422);
    }

    // ------------------------------------------------------------------ facility registration eligibility

    public function test_a_facility_created_at_22_00_utc_on_31_october_is_eligible_from_november_not_october(): void
    {
        $this->freeze('2026-11-15 10:00:00');
        $newcomer = $this->makeCompany('Newcomer Offices');
        $justBefore = $this->makeCompany('Registered 23:59:59 Mogadishu');
        $exactly = $this->makeCompany('Registered 00:00:00 Mogadishu');
        ClientCompany::query()->whereKey($newcomer->id)->update(['created_at' => '2026-10-31 22:00:00']);       // 01:00 on 1 November in Mogadishu
        ClientCompany::query()->whereKey($justBefore->id)->update(['created_at' => '2026-10-31 20:59:59']);     // 23:59:59 on 31 October
        ClientCompany::query()->whereKey($exactly->id)->update(['created_at' => '2026-10-31 21:00:00']);        // 00:00:00 on 1 November

        $october = $this->overview(['month' => '2026-10']);
        $november = $this->overview(['month' => '2026-11']);

        $this->assertNull($this->row($october, $newcomer), 'not yet registered in October (Mogadishu)');
        $this->assertNotNull($this->row($october, $justBefore), 'registered in the last second of October');
        $this->assertNull($this->row($october, $exactly), 'the first instant of November belongs to November');
        foreach ([$newcomer, $justBefore, $exactly] as $company) {
            $this->assertNotNull($this->row($november, $company), $company->name.' is eligible in November');
        }
    }

    // ------------------------------------------------------------------ date-only values keep their day

    public function test_a_completion_date_keeps_its_exact_day_through_create_read_edit_and_void(): void
    {
        $this->freeze('2026-10-31 21:30:00'); // 00:30 on 1 November in Mogadishu
        $id = $this->cleaned($this->alpha, '2026-10-31', '2026-11-01');
        $detail = fn () => $this->as($this->viewer)->getJson("/api/v1/admin/hr/facility-inspections/{$id}")->assertOk()->json('data');
        $stored = fn () => substr((string) DB::table('facility_deep_cleanings')->value('completed_on'), 0, 10);

        $this->assertSame('2026-10-31', $detail()['deep_cleaning']['completed_on']);
        $this->assertSame('2026-10-31', $detail()['deep_cleaning_completed_on']);
        $this->assertSame('2026-10-31', $stored());
        $this->assertSame('2026-11-01', $detail()['inspected_on']);

        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['deep_cleaning' => ['completed' => true, 'completed_on' => '2026-11-01', 'change_reason' => 'It was done after midnight']])->assertOk();
        $this->assertSame('2026-11-01', $detail()['deep_cleaning']['completed_on']);
        $this->assertSame('2026-11-01', $stored());

        $this->as($this->manager)->postJson("/api/v1/admin/hr/facility-inspections/{$id}/void", ['reason' => 'Recorded for the wrong site'])->assertOk();
        $this->assertSame('2026-11-01', $stored(), 'withdrawing keeps the recorded day');
        $this->assertSame('2026-11-01', $detail()['deep_cleaning']['completed_on']);
        $this->assertTrue($detail()['deep_cleaning']['withdrawn']);
        $this->assertSame('2026-11-01', $this->rawDay('facility_inspections', 'inspected_on', $id), 'the inspection date did not move either');
    }

    public function test_historical_inspection_dates_and_the_inspection_board_are_untouched(): void
    {
        $this->freeze('2026-10-31 10:00:00'); // an ordinary working day: 31 October in both calendars
        $old = (int) $this->submit($this->alpha, ['inspected_on' => '2026-10-31'])->assertCreated()->json('data.id');   // before the feature records anything
        $edge = (int) $this->submit($this->beta, ['inspected_on' => '2026-09-30'])->assertCreated()->json('data.id');

        $this->freeze('2026-10-31 21:00:00');
        $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$old}", ['deep_cleaning' => ['completed' => true, 'completed_on' => '2026-10-31']])->assertOk();

        $this->assertSame('2026-10-31', $this->rawDay('facility_inspections', 'inspected_on', $old));
        $this->assertSame('2026-09-30', $this->rawDay('facility_inspections', 'inspected_on', $edge));
        $list = collect($this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections')->assertOk()->json('data'))->pluck('inspected_on', 'id');
        $this->assertSame('2026-10-31', $list[$old]);
        $this->assertSame('2026-09-30', $list[$edge]);
        $board = collect($this->as($this->viewer)->getJson('/api/v1/admin/hr/facility-inspections/status')->assertOk()->json('data'))->pluck('last_inspected_on', 'client_company_id');
        $this->assertSame('2026-10-31', $board[$this->alpha->id]);
        $this->assertSame('2026-09-30', $board[$this->beta->id]);
    }

    // ------------------------------------------------------------------ monthly counts, filters, history

    public function test_counts_filters_and_the_history_strip_use_the_mogadishu_months(): void
    {
        $gamma = $this->makeCompany('Gamma Guri', 'guri');
        $delta = $this->makeCompany('Delta Clinic');
        ClientCompany::query()->update(['created_at' => '2026-01-01 08:00:00']);

        $this->freeze('2026-10-31 21:00:00'); // it is already 1 November in Mogadishu
        $this->cleaned($this->alpha, '2026-10-31');
        $this->cleaned($this->beta, '2026-11-01', '2026-11-01');
        $this->cleaned($delta, '2026-08-31');

        $november = $this->overview();
        $this->assertSame('2026-11', $november['meta']['month']['value']);
        $this->assertSame(['completed' => 1, 'not_completed' => 1, 'overdue_multiple' => 1, 'no_history' => 1, 'total' => 4], $november['meta']['counts']);
        $this->assertSame('not_completed', $this->row($november, $this->alpha)['state'], '31 October does not satisfy November');
        $this->assertSame('completed', $this->row($november, $this->beta)['state']);
        $this->assertSame('overdue_multiple', $this->row($november, $delta)['state']);
        $this->assertSame(3, $this->row($november, $delta)['months_without'], 'September, October and November');
        $this->assertSame('no_history', $this->row($november, $gamma)['state']);
        $this->assertSame(
            ['2026-06' => false, '2026-07' => false, '2026-08' => true, '2026-09' => false, '2026-10' => false, '2026-11' => false],
            collect($this->row($november, $delta)['history'])->pluck('completed', 'month')->all(),
            'the strip for Delta reads Jun..Nov, with only August done',
        );

        // The four filters are mutually exclusive and together cover every facility.
        $seen = [];
        foreach (['completed', 'not_completed', 'overdue_multiple', 'no_history'] as $state) {
            $ids = array_column($this->overview(['state' => $state])['data'], 'client_company_id');
            $this->assertSame([], array_intersect($seen, $ids), "{$state} overlaps another state");
            $seen = array_merge($seen, $ids);
            $this->assertSame($november['meta']['counts'][$state], count($ids));
        }
        $this->assertEqualsCanonicalizing(array_column($november['data'], 'client_company_id'), $seen);

        $october = $this->overview(['month' => '2026-10']);
        $this->assertSame('completed', $this->row($october, $this->alpha)['state']);
        $this->assertSame('no_history', $this->row($october, $this->beta)['state'], 'as of the end of October nothing was recorded for it');
        $this->assertSame('overdue_multiple', $this->row($october, $delta)['state']);
        $this->assertSame(2, $this->row($october, $delta)['months_without']);
        $this->assertSame(['completed' => 1, 'not_completed' => 0, 'overdue_multiple' => 1, 'no_history' => 2, 'total' => 4], $october['meta']['counts']);
    }

    public function test_the_year_boundary_and_leap_february_count_the_right_months(): void
    {
        $this->freeze('2027-01-05 10:00:00');
        $this->cleaned($this->alpha, '2026-12-31');
        $this->cleaned($this->beta, '2026-10-15');

        $january = $this->overview(['month' => '2027-01']);
        $this->assertSame('not_completed', $this->row($january, $this->alpha)['state'], 'December to January is one missed month');
        $this->assertSame(1, $this->row($january, $this->alpha)['months_without']);
        $this->assertSame('overdue_multiple', $this->row($january, $this->beta)['state']);
        $this->assertSame(3, $this->row($january, $this->beta)['months_without'], 'November, December and January');
        $this->assertSame('completed', $this->row($this->overview(['month' => '2026-12']), $this->alpha)['state']);
        $this->assertSame(
            ['2026-08', '2026-09', '2026-10', '2026-11', '2026-12', '2027-01'],
            array_column($this->row($january, $this->alpha)['history'], 'month'),
        );

        $this->freeze('2028-03-05 10:00:00');
        $this->cleaned($this->alpha, '2028-02-29');
        $february = $this->overview(['month' => '2028-02']);
        $this->assertSame(['2028-02-01', '2028-02-29'], [$february['meta']['month']['from'], $february['meta']['month']['to']]);
        $this->assertSame('completed', $this->row($february, $this->alpha)['state'], 'the leap day belongs to February');
        $this->assertSame('not_completed', $this->row($this->overview(['month' => '2028-03']), $this->alpha)['state']);
    }

    // ------------------------------------------------------------------ the rules around it still hold at the boundary

    public function test_rbac_audit_reasons_duplicates_and_withdrawal_still_hold_at_the_boundary(): void
    {
        $this->freeze('2026-10-31 21:00:00');
        $id = $this->cleaned($this->alpha, '2026-11-01', '2026-11-01');

        // Duplicate protection: the same facility and day cannot be recorded twice.
        $this->submit($this->alpha, ['inspected_on' => '2026-11-01', 'deep_cleaning' => ['completed' => true, 'completed_on' => '2026-11-01']])
            ->assertStatus(422)->assertJsonPath('error_code', 'DEEP_CLEANING_ALREADY_RECORDED');
        $this->assertSame(1, FacilityDeepCleaning::query()->count());
        $this->assertSame(1, FacilityInspection::query()->count());

        // Reasons are required, and the audit trail names the actor, the dates and the reason.
        $put = fn (array $deep) => $this->as($this->manager)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['deep_cleaning' => $deep]);
        $put(['completed' => true, 'completed_on' => '2026-10-31'])->assertStatus(422)->assertJsonPath('error_code', 'DEEP_CLEANING_CHANGE_REASON_REQUIRED');
        $put(['completed' => true, 'completed_on' => '2026-10-31', 'change_reason' => 'Done just before midnight'])->assertOk();
        $log = AuditLog::query()->where('entity_type', FacilityDeepCleaning::class)->where('action', 'update')->sole();
        $this->assertSame($this->managerAdmin->id, $log->admin_id);
        $this->assertSame('2026-11-01', $log->metadata['before']['completed_on']);
        $this->assertSame('2026-10-31', $log->metadata['after']['completed_on']);
        $this->assertSame('Done just before midnight', $log->metadata['reason']);

        // RBAC: viewing is not recording.
        $this->as($this->viewer)->putJson("/api/v1/admin/hr/facility-inspections/{$id}", ['deep_cleaning' => ['completed' => false, 'change_reason' => 'Should not be allowed']])->assertForbidden();
        $this->assertNull(FacilityDeepCleaning::query()->sole()->voided_at);

        // Withdrawal needs a reason, is kept as history and stops counting.
        $put(['completed' => false])->assertStatus(422);
        $put(['completed' => false, 'change_reason' => 'Entered for the wrong site'])->assertOk();
        $this->assertNotNull(FacilityDeepCleaning::query()->sole()->voided_at);
        $this->assertSame('no_history', $this->row($this->overview(['month' => '2026-10']), $this->alpha)['state']);
    }
}
