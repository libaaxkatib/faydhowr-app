<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Actions\Marketing\CreateMarketingTeamMemberAction;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\MarketingTeam;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

/**
 * Marketing -> Employees -> Add Team Member. Only the Super Admin and a Marketing Manager of the team may create or change team
 * members; the role is fixed on the server; Team A / Team B isolation holds for the new accounts and for every management call.
 */
class MarketingTeamMemberTest extends TestCase
{
    use RefreshDatabase;

    private MarketingTeam $teamA;

    private MarketingTeam $teamB;

    private Admin $superAdmin;

    private Admin $managerA;

    private Admin $managerB;

    private Admin $managerNoTeam;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamA = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $this->teamB = MarketingTeam::query()->where('name', 'Team B')->firstOrFail();
        $this->superAdmin = Admin::factory()->superAdmin()->create();
        $this->managerA = $this->account(AdminRole::MarketingManager, $this->teamA);
        $this->managerB = $this->account(AdminRole::MarketingManager, $this->teamB);
        $this->managerNoTeam = $this->account(AdminRole::MarketingManager);
    }

    private function account(AdminRole $role, ?MarketingTeam $team = null, bool $marketingPermissions = true): Admin
    {
        $admin = Admin::factory()->create(['role' => $role]);

        if ($marketingPermissions) {
            foreach (['marketing.view', 'marketing.manage', 'marketing.assign', 'marketing.commission.view', 'marketing.reports.view'] as $key) {
                DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
            }
        }

        $team?->members()->attach($admin->id);

        return $admin;
    }

    private function as(Admin $admin): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($admin->createToken('t')->plainTextToken);
    }

    private function payload(array $overrides = []): array
    {
        return array_replace([
            'full_name' => 'New Team Member',
            'email' => 'Member.One@Example.test',
            'phone' => '+252611000001',
            'password' => 'Str0ng-pass-1',
            'team_id' => $this->teamA->id,
        ], $overrides);
    }

    public function test_the_super_admin_creates_a_team_member_in_either_team(): void
    {
        $response = $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload())
            ->assertCreated()
            ->assertJsonPath('data.role', 'marketing_employee')
            ->assertJsonPath('data.status', 'active')
            ->assertJsonPath('data.teams.0.id', $this->teamA->id);

        $member = Admin::query()->findOrFail($response->json('data.id'));
        $this->assertSame('member.one@example.test', $member->email, 'the e-mail is stored lower-case');
        $this->assertTrue(Hash::check('Str0ng-pass-1', $member->password), 'the password is hashed, never stored in clear');
        $this->assertSame(['marketing.commission.view', 'marketing.manage', 'marketing.view'], DB::table('admin_permissions')->join('permissions', 'permissions.id', '=', 'admin_permissions.permission_id')->where('admin_id', $member->id)->orderBy('key')->pluck('key')->all());
        $this->assertSame(CreateMarketingTeamMemberAction::BASELINE_PERMISSIONS, ['marketing.view', 'marketing.manage', 'marketing.commission.view']);

        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'b@example.test', 'phone' => '+252611000002', 'team_id' => $this->teamB->id]))
            ->assertCreated()->assertJsonPath('data.teams.0.id', $this->teamB->id);
    }

    public function test_creation_and_team_assignment_are_audited(): void
    {
        $id = $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload())->assertCreated()->json('data.id');

        $this->assertDatabaseHas('audit_logs', ['entity_type' => Admin::class, 'entity_id' => $id, 'action' => 'create', 'admin_id' => $this->superAdmin->id]);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => MarketingTeam::class, 'entity_id' => $this->teamA->id, 'action' => 'update', 'admin_id' => $this->superAdmin->id]);
        $this->assertStringNotContainsString('Str0ng-pass-1', (string) DB::table('audit_logs')->pluck('metadata')->implode(' '), 'no password reaches the audit log');
    }

    public function test_a_marketing_manager_creates_members_in_both_teams(): void
    {
        $this->as($this->managerA)->postJson('/api/v1/admin/marketing/employees', $this->payload())->assertCreated()->assertJsonPath('data.teams.0.id', $this->teamA->id);
        $this->as($this->managerA)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'other@example.test', 'phone' => '+252611000009', 'team_id' => $this->teamB->id]))
            ->assertCreated()->assertJsonPath('data.teams.0.id', $this->teamB->id)->assertJsonPath('data.role', 'marketing_employee');
        $this->as($this->managerB)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'third@example.test', 'phone' => '+252611000010', 'team_id' => $this->teamA->id]))->assertCreated();

        $this->assertSame(3, Admin::query()->where('role', AdminRole::MarketingEmployee)->count());
    }

    public function test_a_marketing_manager_needs_no_team_membership_to_manage_either_team(): void
    {
        $this->as($this->managerNoTeam)->postJson('/api/v1/admin/marketing/employees', $this->payload())->assertCreated();
        $this->as($this->managerNoTeam)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'nb@example.test', 'phone' => '+252611000012', 'team_id' => $this->teamB->id]))->assertCreated();
        $this->assertSame(2, Admin::query()->where('role', AdminRole::MarketingEmployee)->count());
    }

    public function test_the_role_cannot_be_chosen_through_the_request(): void
    {
        foreach (['super_admin', 'marketing_manager', 'hr_manager', 'manager'] as $i => $role) {
            $id = $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => "r{$i}@example.test", 'phone' => '+25261100010'.$i, 'role' => $role, 'permissions' => ['admins.manage']]))
                ->assertCreated()->json('data.id');

            $this->assertSame(AdminRole::MarketingEmployee, Admin::query()->findOrFail($id)->role);
            $this->assertDatabaseMissing('admin_permissions', ['admin_id' => $id, 'permission_id' => Permission::query()->where('key', 'admins.manage')->value('id')]);
        }
    }

    public function test_everyone_else_is_refused_on_the_server(): void
    {
        $member = $this->account(AdminRole::MarketingEmployee, $this->teamA);
        $noMarketingPermission = $this->account(AdminRole::MarketingManager, $this->teamA, marketingPermissions: false);
        $hrManager = $this->account(AdminRole::HrManager, null, marketingPermissions: false);
        $mobile = $this->account(AdminRole::MobileAppManager, null, marketingPermissions: false);

        $this->postJson('/api/v1/admin/marketing/employees', $this->payload())->assertUnauthorized();

        foreach ([$member, $noMarketingPermission, $hrManager, $mobile] as $who) {
            $this->as($who)->postJson('/api/v1/admin/marketing/employees', $this->payload())->assertForbidden();
        }

        $this->assertSame(1, Admin::query()->where('role', AdminRole::MarketingEmployee)->count(), 'only the pre-existing member exists');
    }

    public function test_validation_duplicates_and_assignments(): void
    {
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload())->assertCreated();

        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', [])->assertUnprocessable()->assertJsonPath('error_code', 'VALIDATION_ERROR')
            ->assertJsonValidationErrors(['full_name', 'email', 'phone', 'password', 'team_id'], 'errors');
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['phone' => '+252611000077']))->assertUnprocessable()->assertJsonPath('errors.email.0', fn ($m) => is_string($m)); // same e-mail, different case
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'fresh@example.test']))->assertUnprocessable(); // same phone
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'f2@example.test', 'phone' => '+252611000078', 'password' => 'short']))->assertUnprocessable();
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'f3@example.test', 'phone' => '+252611000079', 'team_id' => 99999]))->assertUnprocessable();
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'f4@example.test', 'phone' => '+252611000080', 'status' => 'banned']))->assertUnprocessable();
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'f5@example.test', 'phone' => '+252611000081', 'team_id' => 'abc']))->assertUnprocessable();

        $this->assertSame(1, Admin::query()->where('role', AdminRole::MarketingEmployee)->count(), 'a rejected request creates nothing');
    }

    public function test_the_new_member_can_sign_in_sees_only_their_team_and_cannot_manage_anyone(): void
    {
        $this->as($this->superAdmin)->postJson('/api/v1/admin/marketing/employees', $this->payload())->assertCreated();
        $this->app['auth']->forgetGuards();
        $login = $this->postJson('/api/v1/admin/auth/login', ['email' => 'member.one@example.test', 'password' => 'Str0ng-pass-1'])->assertOk();
        $token = $login->json('data.token') ?? $login->json('data.access_token');
        $this->assertNotEmpty($token);

        // a record in each team, made by the manager of that team
        $a = $this->as($this->managerA)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Alpha Co', 'assigned_team_id' => $this->teamA->id])->assertCreated()->json('data.id');
        $b = $this->as($this->managerB)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Bravo Co', 'assigned_team_id' => $this->teamB->id])->assertCreated()->json('data.id');

        $this->app['auth']->forgetGuards();
        $ids = array_column($this->withToken($token)->getJson('/api/v1/admin/marketing/records')->assertOk()->json('data'), 'id');
        $this->assertContains($a, $ids);
        $this->assertNotContains($b, $ids);

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson("/api/v1/admin/marketing/records/{$b}")->assertNotFound();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->postJson('/api/v1/admin/marketing/employees', $this->payload(['email' => 'x@example.test', 'phone' => '+252611000090']))->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/hr/employees')->assertForbidden();
    }

    public function test_a_manager_changes_team_employees_of_either_team_but_never_other_account_types(): void
    {
        $inA = $this->account(AdminRole::MarketingEmployee, $this->teamA);
        $inB = $this->account(AdminRole::MarketingEmployee, $this->teamB);
        $noTeam = $this->account(AdminRole::MarketingEmployee);

        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$inA->id}", ['full_name' => 'Renamed', 'role' => 'super_admin', 'email' => 'hijack@example.test'])
            ->assertOk()->assertJsonPath('data.full_name', 'Renamed')->assertJsonPath('data.role', 'marketing_employee');
        $this->assertNotSame('hijack@example.test', $inA->fresh()->email, 'e-mail and role are not changed here');
        $this->assertSame(AdminRole::MarketingEmployee, $inA->fresh()->role);

        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$inB->id}", ['full_name' => 'Both teams'])->assertOk();
        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$noTeam->id}", ['status' => 'inactive'])->assertOk();
        $this->assertSame('Both teams', $inB->fresh()->full_name);

        // a manager, the Super Admin and every other account type answer as if they did not exist
        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$this->managerB->id}", ['status' => 'inactive'])->assertNotFound();
        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$this->superAdmin->id}", ['status' => 'inactive'])->assertNotFound();
        $hr = $this->account(AdminRole::HrManager, null, marketingPermissions: false);
        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$hr->id}", ['status' => 'inactive'])->assertNotFound();
        $this->as($inA)->patchJson("/api/v1/admin/marketing/employees/{$inB->id}", ['full_name' => 'Self'])->assertForbidden();

        $this->as($this->superAdmin)->patchJson("/api/v1/admin/marketing/employees/{$inB->id}", ['phone' => '+252611000555'])->assertOk();
        $this->assertSame('+252611000555', $inB->fresh()->phone);
        $this->assertSame(AdminRole::MarketingManager, $this->managerB->fresh()->role);
        $this->assertSame('active', $hr->fresh()->status->value);
    }

    public function test_an_inactive_member_cannot_sign_in_or_call_the_api_and_can_be_reactivated(): void
    {
        $member = $this->account(AdminRole::MarketingEmployee, $this->teamA);
        $member->update(['password' => 'Str0ng-pass-1']);
        $token = $member->createToken('t')->plainTextToken;

        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$member->id}", ['status' => 'inactive'])->assertOk()->assertJsonPath('data.status', 'inactive');

        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/admin/marketing/records')->assertStatus(403);
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/admin/auth/login', ['email' => $member->email, 'password' => 'Str0ng-pass-1'])->assertStatus(403);

        $this->as($this->managerA)->patchJson("/api/v1/admin/marketing/employees/{$member->id}", ['status' => 'active'])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/v1/admin/auth/login', ['email' => $member->email, 'password' => 'Str0ng-pass-1'])->assertOk();
    }

    public function test_team_membership_endpoints_follow_the_same_authority(): void
    {
        $inB = $this->account(AdminRole::MarketingEmployee, $this->teamB);
        $free = $this->account(AdminRole::MarketingEmployee);
        $member = $this->account(AdminRole::MarketingEmployee, $this->teamA);

        // a Marketing Manager manages both teams
        $this->as($this->managerA)->postJson("/api/v1/admin/marketing/teams/{$this->teamB->id}/members", ['admin_id' => $free->id])->assertOk();
        $this->as($this->managerA)->deleteJson("/api/v1/admin/marketing/teams/{$this->teamB->id}/members/{$inB->id}")->assertOk();
        $this->as($this->managerB)->postJson("/api/v1/admin/marketing/teams/{$this->teamA->id}/members", ['admin_id' => $inB->id])->assertOk();
        $this->assertTrue($this->teamB->members()->whereKey($free->id)->exists());
        $this->assertTrue($this->teamA->members()->whereKey($inB->id)->exists());

        // only marketing accounts can join
        $hr = $this->account(AdminRole::HrManager, null, marketingPermissions: false);
        $this->as($this->managerA)->postJson("/api/v1/admin/marketing/teams/{$this->teamA->id}/members", ['admin_id' => $hr->id])->assertUnprocessable()->assertJsonPath('error_code', 'INVALID_TEAM_MEMBER');
        $this->as($this->managerA)->postJson("/api/v1/admin/marketing/teams/{$this->teamA->id}/members", ['admin_id' => $this->superAdmin->id])->assertUnprocessable();
        $this->assertFalse($this->teamA->members()->whereKey($hr->id)->exists());

        // a team member - even holding marketing.manage - and any non-marketing account cannot change membership at all
        $this->as($member)->postJson("/api/v1/admin/marketing/teams/{$this->teamB->id}/members", ['admin_id' => $member->id])->assertForbidden();
        $this->as($member)->deleteJson("/api/v1/admin/marketing/teams/{$this->teamA->id}/members/{$free->id}")->assertForbidden();
        $this->as($hr)->postJson("/api/v1/admin/marketing/teams/{$this->teamA->id}/members", ['admin_id' => $free->id])->assertForbidden();
        $this->assertFalse($this->teamB->members()->whereKey($member->id)->exists());

        $this->as($this->superAdmin)->postJson("/api/v1/admin/marketing/teams/{$this->teamB->id}/members", ['admin_id' => $member->id])->assertOk();
        $this->as($this->superAdmin)->deleteJson("/api/v1/admin/marketing/teams/{$this->teamB->id}/members/{$member->id}")->assertOk();
    }

    public function test_the_team_list_tells_the_panel_which_teams_the_caller_may_manage(): void
    {
        $member = $this->account(AdminRole::MarketingEmployee, $this->teamA);
        $byId = fn (Admin $who) => collect($this->as($who)->getJson('/api/v1/admin/marketing/teams')->assertOk()->json('data'))->pluck('can_manage_members', 'id');
        $both = [$this->teamA->id, $this->teamB->id];

        foreach ([$this->superAdmin, $this->managerA, $this->managerB, $this->managerNoTeam] as $who) {
            $this->assertSame([$this->teamA->id => true, $this->teamB->id => true], $byId($who)->only($both)->all());
        }

        $this->assertSame([$this->teamA->id => false], $byId($member)->all(), 'a team member sees only their own team and may manage none');
    }

    public function test_inactive_accounts_are_listed_only_on_request_and_only_for_unrestricted_callers(): void
    {
        $member = $this->account(AdminRole::MarketingEmployee, $this->teamA);
        $member->update(['status' => 'inactive']);
        $ids = fn (Admin $who, string $query = '') => array_column($this->as($who)->getJson('/api/v1/admin/marketing/employees'.$query)->assertOk()->json('data'), 'id');

        $this->assertNotContains($member->id, $ids($this->managerA));
        $this->assertContains($member->id, $ids($this->managerA, '?include_inactive=1'));

        $peer = $this->account(AdminRole::MarketingEmployee, $this->teamA);
        $this->assertNotContains($member->id, $ids($peer, '?include_inactive=1'), 'a restricted caller never sees inactive accounts');
    }
}
