<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\CommissionRecord;
use App\Models\FollowUp;
use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Approved decision: Team A and Team B each see their own work; the Marketing
 * Manager and the Super Admin see both teams. The boundary is enforced on the
 * server for every Marketing endpoint, not by what the web panel shows.
 */
class MarketingTeamIsolationTest extends TestCase
{
    use RefreshDatabase;

    private MarketingTeam $teamA;

    private MarketingTeam $teamB;

    private Admin $memberA;

    private Admin $memberB;

    private Admin $manager;

    private Admin $superAdmin;

    private MarketingRecord $recordA;

    private MarketingRecord $recordB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamA = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $this->teamB = MarketingTeam::query()->where('name', 'Team B')->firstOrFail();
        $this->memberA = $this->makeMember(AdminRole::MarketingEmployee, $this->teamA);
        $this->memberB = $this->makeMember(AdminRole::MarketingEmployee, $this->teamB);
        $this->manager = $this->makeMember(AdminRole::MarketingManager);
        $this->superAdmin = Admin::factory()->superAdmin()->create();

        $this->recordA = $this->makeRecord($this->teamA, $this->memberA, 'Facility Alpha');
        $this->recordB = $this->makeRecord($this->teamB, $this->memberB, 'Facility Bravo');
    }

    private function makeMember(AdminRole $role, ?MarketingTeam $team = null): Admin
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach (AdminPermission::cases() as $permission) {
            if (str_starts_with($permission->value, 'marketing.')) {
                DB::table('admin_permissions')->insert([
                    'admin_id' => $admin->id,
                    'permission_id' => Permission::query()->where('key', $permission->value)->value('id'),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }
        }

        $team?->members()->attach($admin->id);

        return $admin;
    }

    private function makeRecord(MarketingTeam $team, Admin $member, string $facility): MarketingRecord
    {
        $token = $this->superAdmin()->createToken('seed')->plainTextToken;
        $id = $this->withToken($token)->postJson('/api/v1/admin/marketing/xarun', [
            'facility_name' => $facility,
            'assigned_team_id' => $team->id,
            'assigned_admin_id' => $member->id,
        ])->assertCreated()->json('data.id');

        return MarketingRecord::query()->findOrFail($id);
    }

    private function superAdmin(): Admin
    {
        return $this->superAdmin ??= Admin::factory()->superAdmin()->create();
    }

    private function as(Admin $admin): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($admin->createToken('t')->plainTextToken);
    }

    /** @return list<int> */
    private function ids(array $response): array
    {
        return array_map(fn ($row) => $row['id'], $response['data']);
    }

    public function test_each_team_lists_only_its_own_records(): void
    {
        $a = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/records')->assertOk()->json();
        $this->assertSame([$this->recordA->id], $this->ids($a));

        $b = $this->as($this->memberB)->getJson('/api/v1/admin/marketing/records')->assertOk()->json();
        $this->assertSame([$this->recordB->id], $this->ids($b));
    }

    public function test_the_marketing_manager_and_super_admin_see_both_teams(): void
    {
        foreach ([$this->manager, $this->superAdmin] as $admin) {
            $ids = $this->ids($this->as($admin)->getJson('/api/v1/admin/marketing/records')->assertOk()->json());
            sort($ids);
            $this->assertSame([$this->recordA->id, $this->recordB->id], $ids);
        }
    }

    public function test_the_other_teams_record_answers_404_for_every_record_endpoint(): void
    {
        $foreign = $this->recordB->id;
        $request = $this->as($this->memberA);

        $request->getJson("/api/v1/admin/marketing/records/{$foreign}")->assertNotFound();
        $request->patchJson("/api/v1/admin/marketing/records/{$foreign}/status", ['status' => 'cancelled'])->assertNotFound();
        $request->patchJson("/api/v1/admin/marketing/records/{$foreign}/assign", ['assigned_team_id' => $this->teamA->id])->assertNotFound();
        $request->putJson("/api/v1/admin/marketing/xarun/{$foreign}", ['facility_name' => 'Hijacked'])->assertNotFound();
        $request->postJson("/api/v1/admin/marketing/records/{$foreign}/follow-ups", ['follow_up_date' => now()->addDay()->toDateString()])->assertNotFound();
        $request->postJson("/api/v1/admin/marketing/records/{$foreign}/quotations", ['amount' => 10])->assertNotFound();
        $request->postJson("/api/v1/admin/marketing/records/{$foreign}/commission", ['admin_id' => $this->memberA->id, 'reference_date' => now()->toDateString()])->assertNotFound();

        $this->assertDatabaseHas('marketing_records', ['id' => $foreign, 'status' => 'pending', 'assigned_team_id' => $this->teamB->id]);
        $this->assertDatabaseCount('follow_ups', 0);
        $this->assertDatabaseCount('commission_records', 0);
    }

    public function test_the_symmetric_case_team_b_cannot_reach_team_a(): void
    {
        $this->as($this->memberB)->getJson("/api/v1/admin/marketing/records/{$this->recordA->id}")->assertNotFound();
        $this->as($this->memberB)->patchJson("/api/v1/admin/marketing/records/{$this->recordA->id}/status", ['status' => 'done'])->assertNotFound();
    }

    public function test_a_team_member_can_work_on_their_own_record(): void
    {
        $this->as($this->memberA)->getJson("/api/v1/admin/marketing/records/{$this->recordA->id}")->assertOk();
        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/records/{$this->recordA->id}/status", ['status' => 'quotation'])->assertOk();
        $this->as($this->memberA)->postJson("/api/v1/admin/marketing/records/{$this->recordA->id}/follow-ups", ['follow_up_date' => now()->addDay()->toDateString()])->assertCreated();
    }

    public function test_follow_ups_are_scoped_and_the_other_teams_follow_up_cannot_be_changed(): void
    {
        $date = now()->addDay()->toDateString();
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$this->recordA->id}/follow-ups", ['follow_up_date' => $date, 'assigned_admin_id' => $this->memberA->id])->assertCreated();
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$this->recordB->id}/follow-ups", ['follow_up_date' => $date, 'assigned_admin_id' => $this->memberB->id])->assertCreated();
        $foreign = FollowUp::query()->where('marketing_record_id', $this->recordB->id)->firstOrFail();

        $list = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/follow-ups')->assertOk()->json();
        $this->assertCount(1, $list['data']);
        $this->assertSame($this->recordA->id, $list['data'][0]['marketing_record_id']);

        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/follow-ups/{$foreign->id}/complete", [])->assertNotFound();
        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/follow-ups/{$foreign->id}/reschedule", ['follow_up_date' => $date])->assertNotFound();
        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/follow-ups/{$foreign->id}/feedback", ['feedback' => 'x'])->assertNotFound();
        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/follow-ups/{$foreign->id}/status", ['status' => 'done'])->assertNotFound();
    }

    public function test_quotation_status_of_the_other_team_cannot_be_changed(): void
    {
        $quotationId = $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$this->recordB->id}/quotations", ['amount' => 100])->assertCreated()->json('data.id');

        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/quotations/{$quotationId}/status", ['status' => 'accepted'])->assertNotFound();
    }

    public function test_dashboard_and_reports_count_only_the_callers_work(): void
    {
        $this->as($this->memberA)->getJson('/api/v1/admin/marketing/dashboard')->assertOk()->assertJsonPath('data.total_records', 1);
        $this->as($this->memberB)->getJson('/api/v1/admin/marketing/dashboard')->assertOk()->assertJsonPath('data.total_records', 1);
        $this->as($this->manager)->getJson('/api/v1/admin/marketing/dashboard')->assertOk()->assertJsonPath('data.total_records', 2);
        $this->as($this->superAdmin)->getJson('/api/v1/admin/marketing/dashboard')->assertOk()->assertJsonPath('data.total_records', 2);

        $report = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/reports/summary')->assertOk()->json('data');
        $this->assertSame(1, $report['total_records']);
        $this->assertSame([$this->teamA->id], array_column($report['team_breakdown'], 'id'));

        // Asking for the other team explicitly still yields nothing.
        $asked = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/reports/summary?assigned_team_id='.$this->teamB->id)->assertOk()->json('data');
        $this->assertSame(0, $asked['total_records']);
        $this->assertSame([], $asked['team_breakdown']);

        $all = $this->as($this->manager)->getJson('/api/v1/admin/marketing/reports/summary')->assertOk()->json('data');
        $this->assertSame(2, $all['total_records']);
        $this->assertCount(2, array_filter($all['team_breakdown'], fn ($t) => in_array($t['id'], [$this->teamA->id, $this->teamB->id], true)));
    }

    public function test_filters_cannot_widen_a_restricted_callers_view(): void
    {
        $ids = $this->ids($this->as($this->memberA)->getJson('/api/v1/admin/marketing/records?assigned_team_id='.$this->teamB->id)->assertOk()->json());
        $this->assertSame([], $ids);

        // The record search uses PostgreSQL's ilike, so it can only run on PostgreSQL.
        if (DB::getDriverName() === 'pgsql') {
            $search = $this->ids($this->as($this->memberA)->getJson('/api/v1/admin/marketing/records?search=Bravo')->assertOk()->json());
            $this->assertSame([], $search);
        }
    }

    public function test_teams_and_employees_lists_expose_only_the_callers_own_team(): void
    {
        $teams = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/teams')->assertOk()->json('data');
        $this->assertSame([$this->teamA->id], array_column($teams, 'id'));

        $employees = array_column($this->as($this->memberA)->getJson('/api/v1/admin/marketing/employees')->assertOk()->json('data'), 'id');
        $this->assertContains($this->memberA->id, $employees);
        $this->assertNotContains($this->memberB->id, $employees);

        $this->assertCount(2, array_filter(array_column($this->as($this->manager)->getJson('/api/v1/admin/marketing/teams')->json('data'), 'id'), fn ($id) => in_array($id, [$this->teamA->id, $this->teamB->id], true)));
    }

    public function test_a_restricted_member_cannot_move_work_to_the_other_team_or_create_into_it(): void
    {
        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/records/{$this->recordA->id}/assign", ['assigned_team_id' => $this->teamB->id])
            ->assertForbidden()->assertJsonPath('error_code', 'MARKETING_SCOPE_FORBIDDEN');
        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/records/{$this->recordA->id}/assign", ['assigned_admin_id' => $this->memberB->id])->assertForbidden();
        $this->as($this->memberA)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Sneaky', 'assigned_team_id' => $this->teamB->id])->assertForbidden();
        $this->as($this->memberA)->postJson('/api/v1/admin/marketing/project', ['responsible_party_type' => 'engineer', 'assigned_team_id' => $this->teamB->id])->assertForbidden();

        $this->assertSame($this->teamA->id, $this->recordA->fresh()->assigned_team_id);
        $this->assertDatabaseMissing('marketing_records', ['assigned_team_id' => $this->teamB->id, 'created_by' => $this->memberA->id]);

        $own = $this->as($this->memberA)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Own Team Work', 'assigned_team_id' => $this->teamA->id])->assertCreated()->json('data.id');
        $this->assertContains($own, $this->ids($this->as($this->memberA)->getJson('/api/v1/admin/marketing/records')->json()));
        $this->assertNotContains($own, $this->ids($this->as($this->memberB)->getJson('/api/v1/admin/marketing/records')->json()));
    }

    public function test_the_manager_can_reassign_across_teams(): void
    {
        $this->as($this->manager)->patchJson("/api/v1/admin/marketing/records/{$this->recordA->id}/assign", ['assigned_team_id' => $this->teamB->id, 'assigned_admin_id' => $this->memberB->id])
            ->assertOk()->assertJsonPath('data.assigned_team_id', $this->teamB->id);
    }

    public function test_team_administration_is_manager_only(): void
    {
        $this->as($this->memberA)->postJson('/api/v1/admin/marketing/teams', ['name' => 'Team Z'])->assertForbidden();
        $this->as($this->memberA)->postJson("/api/v1/admin/marketing/teams/{$this->teamB->id}/members", ['admin_id' => $this->memberA->id])->assertForbidden();
        $this->as($this->memberA)->deleteJson("/api/v1/admin/marketing/teams/{$this->teamB->id}")->assertForbidden();
        $this->as($this->memberA)->postJson('/api/v1/admin/marketing/commission/rates', ['rate_type' => 'percentage', 'rate_value' => 5, 'effective_from' => now()->toDateString()])->assertForbidden();

        $this->assertDatabaseHas('marketing_teams', ['id' => $this->teamB->id]);
        $this->as($this->manager)->postJson('/api/v1/admin/marketing/teams', ['name' => 'Team Z'])->assertCreated();
    }

    public function test_commission_is_recorded_manually_and_never_calculated(): void
    {
        $date = now()->toDateString();

        // A member can log that they brought their own work, without an amount.
        $logged = $this->as($this->memberA)->postJson("/api/v1/admin/marketing/records/{$this->recordA->id}/commission", ['admin_id' => $this->memberA->id, 'reference_date' => $date])
            ->assertCreated()->assertJsonPath('data.status', 'pending_calculation')->assertJsonPath('data.amount', null)->json('data.id');

        // ... but cannot put a figure on it, nor log for the other team's member.
        $this->as($this->memberA)->postJson("/api/v1/admin/marketing/records/{$this->recordA->id}/commission", ['admin_id' => $this->memberA->id, 'reference_date' => $date, 'amount' => 50])->assertForbidden();
        $this->as($this->memberA)->postJson("/api/v1/admin/marketing/records/{$this->recordA->id}/commission", ['admin_id' => $this->memberB->id, 'reference_date' => $date])->assertForbidden();
        $this->as($this->memberA)->patchJson("/api/v1/admin/marketing/commission/records/{$logged}", ['amount' => 50])->assertForbidden();

        // The manager types the agreed figure; nothing is derived from a rate.
        $this->as($this->manager)->patchJson("/api/v1/admin/marketing/commission/records/{$logged}", ['amount' => '75.50', 'notes' => 'Agreed verbally'])
            ->assertOk()->assertJsonPath('data.amount', '75.50')->assertJsonPath('data.status', 'recorded')->assertJsonPath('data.commission_rate_id', null);

        $direct = $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$this->recordB->id}/commission", ['admin_id' => $this->memberB->id, 'reference_date' => $date, 'amount' => 20])
            ->assertCreated()->assertJsonPath('data.status', 'recorded')->assertJsonPath('data.amount', '20.00')->json('data.id');

        $this->as($this->manager)->patchJson("/api/v1/admin/marketing/commission/records/{$direct}", ['amount' => -5])->assertUnprocessable();
        $this->as($this->manager)->patchJson("/api/v1/admin/marketing/commission/records/{$direct}", [])->assertUnprocessable();

        CommissionRecord::query()->whereKey($direct)->update(['status' => 'paid']);
        $this->as($this->manager)->patchJson("/api/v1/admin/marketing/commission/records/{$direct}", ['amount' => 999])->assertUnprocessable()->assertJsonPath('error_code', 'COMMISSION_RECORD_CLOSED');

        $this->assertDatabaseHas('audit_logs', ['entity_type' => CommissionRecord::class, 'entity_id' => $logged, 'action' => 'update']);
    }

    public function test_commission_lists_show_a_member_only_their_own_entries(): void
    {
        $date = now()->toDateString();
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$this->recordA->id}/commission", ['admin_id' => $this->memberA->id, 'reference_date' => $date, 'amount' => 10])->assertCreated();
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$this->recordB->id}/commission", ['admin_id' => $this->memberB->id, 'reference_date' => $date, 'amount' => 30])->assertCreated();

        $own = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/commission/records')->assertOk()->json('data');
        $this->assertSame([$this->memberA->id], array_values(array_unique(array_column($own, 'admin_id'))));
        $this->assertCount(1, $own);

        $asked = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/commission/records?admin_id='.$this->memberB->id)->assertOk()->json('data');
        $this->assertSame([], $asked);

        $this->assertCount(2, $this->as($this->manager)->getJson('/api/v1/admin/marketing/commission/records')->json('data'));
        $this->assertCount(2, $this->as($this->superAdmin)->getJson('/api/v1/admin/marketing/commission/records')->json('data'));
    }
}
