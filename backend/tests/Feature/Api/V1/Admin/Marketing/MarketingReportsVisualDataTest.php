<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\FollowUp;
use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The extra figures the Marketing Reports charts draw: XARUN and PROJECT side by side by status, and the follow-ups of the counted records
 * (by status, overdue, due today, and the open work of each person). They use the same team scope and filters as the existing totals and are read-only.
 */
class MarketingReportsVisualDataTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const URL = '/api/v1/admin/marketing/reports/summary';

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

    private function record(string $type, array $fields, ?int $teamId = null): MarketingRecord
    {
        $body = $type === 'xarun' ? ['facility_name' => $fields['name']] : ['responsible_party_type' => 'engineer', 'company_name' => $fields['name']];
        $id = $this->as($this->super)->postJson("/api/v1/admin/marketing/{$type}", [...$body, 'assigned_team_id' => $teamId])->assertCreated()->json('data.id');
        MarketingRecord::query()->whereKey($id)->update(['status' => $fields['status'] ?? 'pending']);

        return MarketingRecord::query()->findOrFail($id);
    }

    private function followUp(MarketingRecord $record, string $date, string $status = 'scheduled', ?int $adminId = null): FollowUp
    {
        return FollowUp::query()->create(['marketing_record_id' => $record->id, 'follow_up_date' => $date, 'status' => $status, 'assigned_admin_id' => $adminId]);
    }

    private function summary(array $query = [], ?string $token = null): array
    {
        return $this->as($token ?? $this->super)->getJson(self::URL.'?'.http_build_query($query))->assertOk()->json('data');
    }

    public function test_xarun_and_project_are_counted_side_by_side_by_status(): void
    {
        $this->record('xarun', ['name' => 'X1', 'status' => 'pending']);
        $this->record('xarun', ['name' => 'X2', 'status' => 'done']);
        $this->record('xarun', ['name' => 'X3', 'status' => 'done']);
        $this->record('project', ['name' => 'P1', 'status' => 'quotation']);

        $data = $this->summary();

        $this->assertEquals(['xarun' => ['pending' => 1, 'done' => 2], 'project' => ['quotation' => 1]], $data['type_status_breakdown']);
        $this->assertSame(4, $data['total_records'], 'the existing totals are unchanged');
        $this->assertEquals(['xarun' => 3, 'project' => 1], array_map('intval', $data['type_breakdown']));
    }

    public function test_follow_ups_are_summarised_by_status_overdue_due_today_and_open_work_per_person(): void
    {
        $today = now()->toDateString();
        $yesterday = now()->subDay()->toDateString();
        $tomorrow = now()->addDay()->toDateString();
        $ann = Admin::factory()->create(['role' => AdminRole::MarketingEmployee, 'full_name' => 'Ann Worker']);
        $bob = Admin::factory()->create(['role' => AdminRole::MarketingEmployee, 'full_name' => 'Bob Worker']);
        $record = $this->record('xarun', ['name' => 'Clinic']);

        $this->followUp($record, $yesterday, 'scheduled', $ann->id);   // overdue, open
        $this->followUp($record, $yesterday, 'rescheduled', $ann->id); // overdue, open
        $this->followUp($record, $today, 'scheduled', $bob->id);       // due today, open
        $this->followUp($record, $tomorrow, 'scheduled', $bob->id);    // open, later
        $this->followUp($record, $yesterday, 'completed', $bob->id);   // completed is never open or overdue
        $this->followUp($record, $today, 'scheduled', null);           // open, nobody assigned

        $summary = $this->summary()['follow_up_summary'];

        $this->assertEquals(['scheduled' => 4, 'rescheduled' => 1, 'completed' => 1], $summary['by_status']);
        $this->assertSame([5, 2, 2], [$summary['open'], $summary['overdue'], $summary['due_today']]);

        $byName = collect($summary['workload'])->keyBy(fn (array $row): string => (string) ($row['admin_name'] ?? 'unassigned'));
        $this->assertSame([2, 2], [$byName['Ann Worker']['open'], $byName['Ann Worker']['overdue']]);
        $this->assertSame([2, 0], [$byName['Bob Worker']['open'], $byName['Bob Worker']['overdue']]);
        $this->assertSame([1, 0], [$byName['unassigned']['open'], $byName['unassigned']['overdue']]);
        $this->assertSame(['Ann Worker', 'Bob Worker'], array_slice(array_column($summary['workload'], 'admin_name'), 0, 2), 'the busiest first (ties keep a stable order)');
    }

    public function test_the_filters_apply_to_the_follow_ups_as_well(): void
    {
        $teamA = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $teamB = MarketingTeam::query()->where('name', 'Team B')->firstOrFail();
        $a = $this->record('xarun', ['name' => 'A clinic'], $teamA->id);
        $b = $this->record('project', ['name' => 'B builders'], $teamB->id);
        $this->followUp($a, now()->toDateString());
        $this->followUp($b, now()->subDay()->toDateString());
        $this->followUp($b, now()->toDateString());

        $this->assertSame(3, $this->summary()['follow_up_summary']['open']);
        $this->assertSame(1, $this->summary(['assigned_team_id' => $teamA->id])['follow_up_summary']['open']);
        $this->assertSame(2, $this->summary(['type' => 'project'])['follow_up_summary']['open']);
        $this->assertSame(0, $this->summary(['type' => 'project', 'assigned_team_id' => $teamA->id])['follow_up_summary']['open']);
    }

    public function test_a_team_member_only_sees_the_follow_ups_of_their_own_teams_records(): void
    {
        $teamA = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $teamB = MarketingTeam::query()->where('name', 'Team B')->firstOrFail();
        $mine = $this->record('xarun', ['name' => 'Mine'], $teamA->id);
        $theirs = $this->record('xarun', ['name' => 'Theirs'], $teamB->id);
        $this->followUp($mine, now()->toDateString());
        $this->followUp($theirs, now()->toDateString());
        $this->followUp($theirs, now()->subDay()->toDateString());

        $token = $this->tokenForRoleWith(AdminRole::MarketingEmployee, ['marketing.view', 'marketing.reports.view']);
        $employee = Admin::query()->where('role', AdminRole::MarketingEmployee->value)->latest('id')->firstOrFail();
        $teamA->members()->syncWithoutDetaching([$employee->id]);

        $data = $this->summary([], $token);

        $this->assertSame(1, $data['follow_up_summary']['open'], 'only the follow-up of the record of their own team');
        $this->assertSame(0, $data['follow_up_summary']['overdue']);
        $this->assertSame(['xarun' => ['pending' => 1]], $data['type_status_breakdown']);
    }

    public function test_the_report_keeps_its_permission_rules_and_never_changes_data(): void
    {
        $record = $this->record('xarun', ['name' => 'Stable']);
        $followUp = $this->followUp($record, now()->subDay()->toDateString());
        $before = [MarketingRecord::query()->count(), (array) DB::table('follow_ups')->where('id', $followUp->id)->first()];

        $this->summary();

        $this->assertSame($before, [MarketingRecord::query()->count(), (array) DB::table('follow_ups')->where('id', $followUp->id)->first()]);

        $hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.reports.view']);
        $this->as($hr)->getJson(self::URL)->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->flushHeaders()->getJson(self::URL)->assertUnauthorized();
    }
}
