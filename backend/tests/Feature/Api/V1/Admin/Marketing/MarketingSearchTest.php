<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The Marketing text search (records and follow-ups): one rule, case-insensitive, wildcard characters typed literally, and several fields. It only
 * narrows what a person's team scope already allows - it never widens it.
 */
class MarketingSearchTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $super;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = Admin::factory()->superAdmin()->create()->createToken('t')->plainTextToken;
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function xarun(string $facility, array $extra = []): array
    {
        return $this->as($this->super)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => $facility, ...$extra])->assertCreated()->json('data');
    }

    private function project(array $fields): array
    {
        return $this->as($this->super)->postJson('/api/v1/admin/marketing/project', ['responsible_party_type' => 'engineer', ...$fields])->assertCreated()->json('data');
    }

    /** @return list<string> */
    private function recordNumbers(array $query, ?string $token = null): array
    {
        $response = $this->as($token ?? $this->super)->getJson('/api/v1/admin/marketing/records?'.http_build_query($query))->assertOk();

        return array_column($response->json('data'), 'record_number');
    }

    public function test_records_are_found_by_facility_company_person_phone_location_and_number_in_any_case(): void
    {
        $a = $this->xarun('Hodan Medical Centre', ['phone' => '0615550001', 'location' => 'Wadajir']);
        $b = $this->project(['company_name' => 'Skyline Builders', 'responsible_person_name' => 'Eng Yusuf', 'phone' => '0615550002', 'location' => 'Bondhere', 'project_type' => 'Hospital wing']);

        $this->assertSame([$a['record_number']], $this->recordNumbers(['search' => 'hodan']));
        $this->assertSame([$a['record_number']], $this->recordNumbers(['search' => 'MEDICAL CENTRE']));
        $this->assertSame([$b['record_number']], $this->recordNumbers(['search' => 'skyline']), 'a project is found by its company, which the old search ignored');
        $this->assertSame([$b['record_number']], $this->recordNumbers(['search' => 'yusuf']));
        $this->assertSame([$a['record_number']], $this->recordNumbers(['search' => '0615550001']));
        $this->assertSame([$b['record_number']], $this->recordNumbers(['search' => 'bondhere']));
        $this->assertSame([$b['record_number']], $this->recordNumbers(['search' => 'hospital wing']));
        $this->assertSame([$a['record_number']], $this->recordNumbers(['search' => strtolower($a['record_number'])]));
    }

    public function test_search_filters_and_type_and_status_apply_together_and_wildcards_are_literal(): void
    {
        $a = $this->xarun('Alpha Clinic');
        $this->project(['company_name' => 'Alpha Builders']);

        $this->assertCount(2, $this->recordNumbers(['search' => 'alpha']));
        $this->assertSame([$a['record_number']], $this->recordNumbers(['search' => 'alpha', 'type' => 'xarun']));
        $this->assertSame([], $this->recordNumbers(['search' => 'alpha', 'type' => 'xarun', 'status' => 'done']));
        $this->assertSame([], $this->recordNumbers(['search' => '%']), 'a percent sign matches nothing but itself');
        $this->assertSame([], $this->recordNumbers(['search' => '_']));
    }

    public function test_follow_ups_are_found_by_the_record_it_belongs_to_and_by_who_is_assigned(): void
    {
        $record = $this->xarun('Bosaso Dental', ['phone' => '0615559999']);
        $other = $this->xarun('Other Facility');
        $assignee = Admin::factory()->create(['role' => AdminRole::MarketingEmployee, 'full_name' => 'Zainab Follower']);
        $team = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $team->members()->syncWithoutDetaching([$assignee->id]);

        $date = now()->toDateString();
        $one = $this->as($this->super)->postJson("/api/v1/admin/marketing/records/{$record['id']}/follow-ups", ['follow_up_date' => $date, 'assigned_admin_id' => $assignee->id])->assertCreated()->json('data');
        $two = $this->as($this->super)->postJson("/api/v1/admin/marketing/records/{$other['id']}/follow-ups", ['follow_up_date' => $date])->assertCreated()->json('data');

        $ids = fn (array $query): array => array_column($this->as($this->super)->getJson('/api/v1/admin/marketing/follow-ups?'.http_build_query($query))->assertOk()->json('data'), 'id');

        $this->assertEqualsCanonicalizing([$one['id'], $two['id']], $ids(['filter' => 'all']));
        $this->assertSame([$one['id']], $ids(['filter' => 'all', 'search' => 'bosaso']), 'by facility');
        $this->assertSame([$one['id']], $ids(['filter' => 'all', 'search' => '0615559999']), 'by phone');
        $this->assertSame([$one['id']], $ids(['filter' => 'all', 'search' => 'zainab']), 'by the person it is assigned to');
        $this->assertSame([], $ids(['filter' => 'overdue', 'search' => 'bosaso']), 'the tab filter still applies with a search');
        $this->assertSame([$one['id'], $two['id']], array_values(array_intersect($ids(['filter' => 'today']), [$one['id'], $two['id']])));
        $this->assertSame([], $ids(['filter' => 'all', 'search' => '%']));
    }

    public function test_search_never_widens_the_team_scope_of_a_marketing_employee(): void
    {
        $teamA = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $teamB = MarketingTeam::query()->where('name', 'Team B')->firstOrFail();
        $mine = $this->xarun('Shared Name Clinic', ['assigned_team_id' => $teamA->id]);
        $theirs = $this->xarun('Shared Name Hospital', ['assigned_team_id' => $teamB->id]);
        $token = $this->tokenForRoleWith(AdminRole::MarketingEmployee, ['marketing.view']);
        $employee = Admin::query()->where('role', AdminRole::MarketingEmployee->value)->latest('id')->firstOrFail();
        $teamA->members()->syncWithoutDetaching([$employee->id]);

        $this->assertSame([$mine['record_number']], $this->recordNumbers(['search' => 'shared name'], $token));
        $this->assertNotContains($theirs['record_number'], $this->recordNumbers(['search' => 'hospital'], $token));
        $this->assertSame(2, MarketingRecord::query()->count());
    }

    public function test_a_role_without_marketing_access_is_still_refused_whatever_it_searches(): void
    {
        $hr = Admin::factory()->create(['role' => AdminRole::HrManager])->createToken('t')->plainTextToken;

        $this->as($hr)->getJson('/api/v1/admin/marketing/records?search=alpha')->assertForbidden();
        $this->as($hr)->getJson('/api/v1/admin/marketing/follow-ups?search=alpha')->assertForbidden();
    }
}
