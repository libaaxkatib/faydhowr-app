<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Enums\AdminPermission;
use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\CommissionRecord;
use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use App\Models\Permission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * UI audit (finding group E) regression tests: what the panel relies on for the Commission list (month, employee and page filters), for the follow-up
 * editors (the record's saved status and feedback) and for the "Brought by" field of a new XARUN / PROJECT. Synthetic data only.
 */
class MarketingUiAuditETest extends TestCase
{
    use RefreshDatabase;

    private MarketingTeam $teamA;

    private MarketingTeam $teamB;

    private Admin $memberA;

    private Admin $mateA;

    private Admin $memberB;

    private Admin $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->teamA = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $this->teamB = MarketingTeam::query()->where('name', 'Team B')->firstOrFail();
        $this->memberA = $this->makeMember(AdminRole::MarketingEmployee, $this->teamA);
        $this->mateA = $this->makeMember(AdminRole::MarketingEmployee, $this->teamA);
        $this->memberB = $this->makeMember(AdminRole::MarketingEmployee, $this->teamB);
        $this->manager = $this->makeMember(AdminRole::MarketingManager);
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

    private function as(Admin $admin): self
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($admin->createToken('t')->plainTextToken);
    }

    private function makeXarun(Admin $by, array $extra = []): int
    {
        return $this->as($by)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Synthetic Facility', ...$extra])->assertCreated()->json('data.id');
    }

    public function test_commission_entries_filter_by_calendar_month_employee_and_page(): void
    {
        $recordId = $this->makeXarun($this->manager);

        foreach (['2026-07-31', '2026-08-01', '2026-08-15', '2026-08-31', '2026-09-01'] as $date) {
            $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$recordId}/commission", ['admin_id' => $this->memberA->id, 'reference_date' => $date])->assertCreated();
        }
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$recordId}/commission", ['admin_id' => $this->mateA->id, 'reference_date' => '2026-08-10'])->assertCreated();

        $august = $this->as($this->manager)->getJson('/api/v1/admin/marketing/commission/records?month=2026-08')->assertOk();
        $this->assertSame(4, $august->json('meta.total'));

        $augustMemberA = $this->as($this->manager)->getJson('/api/v1/admin/marketing/commission/records?month=2026-08&admin_id='.$this->memberA->id)->assertOk();
        $this->assertSame(3, $augustMemberA->json('meta.total'));

        $paged = $this->as($this->manager)->getJson('/api/v1/admin/marketing/commission/records?month=2026-08&per_page=2&page=2')->assertOk();
        $this->assertSame(2, $paged->json('meta.last_page'));
        $this->assertCount(2, $paged->json('data'));

        // Not a month: matches nothing (it used to be a database error on engines without to_char).
        $this->assertSame(0, $this->as($this->manager)->getJson('/api/v1/admin/marketing/commission/records?month=2026-13')->assertOk()->json('meta.total'));
        $this->assertSame(0, $this->as($this->manager)->getJson('/api/v1/admin/marketing/commission/records?month=abc')->assertOk()->json('meta.total'));
        $this->assertSame(6, CommissionRecord::query()->count());
    }

    public function test_a_team_member_sees_only_their_own_entries_whatever_the_filter(): void
    {
        $recordId = $this->makeXarun($this->manager);
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$recordId}/commission", ['admin_id' => $this->memberA->id, 'reference_date' => '2026-08-01'])->assertCreated();
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$recordId}/commission", ['admin_id' => $this->mateA->id, 'reference_date' => '2026-08-01'])->assertCreated();

        $own = $this->as($this->memberA)->getJson('/api/v1/admin/marketing/commission/records?month=2026-08')->assertOk();
        $this->assertSame(1, $own->json('meta.total'));
        $this->assertSame($this->memberA->id, $own->json('data.0.admin_id'));
    }

    public function test_the_follow_up_list_carries_the_records_saved_status_and_feedback(): void
    {
        $recordId = $this->makeXarun($this->manager);
        $this->as($this->manager)->patchJson("/api/v1/admin/marketing/records/{$recordId}/status", ['status' => 'quotation'])->assertOk();
        MarketingRecord::query()->whereKey($recordId)->update(['feedback' => 'Asked for a price list.']);
        $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$recordId}/follow-ups", ['follow_up_date' => now()->toDateString()])->assertCreated();

        $row = $this->as($this->manager)->getJson('/api/v1/admin/marketing/follow-ups?filter=today')->assertOk()->json('data.0');

        $this->assertSame('quotation', $row['record_status']);
        $this->assertSame('Asked for a price list.', $row['record_feedback']);
    }

    public function test_the_all_filter_lists_completed_follow_ups_but_the_work_tabs_do_not(): void
    {
        $recordId = $this->makeXarun($this->manager);
        $id = $this->as($this->manager)->postJson("/api/v1/admin/marketing/records/{$recordId}/follow-ups", ['follow_up_date' => now()->toDateString()])->assertCreated()->json('data.id');
        $this->as($this->manager)->patchJson("/api/v1/admin/marketing/follow-ups/{$id}/complete", [])->assertOk();

        $this->assertCount(0, $this->as($this->manager)->getJson('/api/v1/admin/marketing/follow-ups?filter=today')->json('data'));
        $all = $this->as($this->manager)->getJson('/api/v1/admin/marketing/follow-ups?filter=all')->assertOk()->json('data');
        $this->assertCount(1, $all);
        $this->assertSame('completed', $all[0]['status']);
    }

    public function test_a_team_member_may_credit_only_themselves_or_a_teammate_as_brought_by(): void
    {
        // Another team's member: refused, nothing created.
        $this->as($this->memberA)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'X', 'brought_by_admin_id' => $this->memberB->id])->assertForbidden();
        $this->as($this->memberA)->postJson('/api/v1/admin/marketing/project', ['responsible_party_type' => 'engineer', 'brought_by_admin_id' => $this->memberB->id])->assertForbidden();
        $this->assertDatabaseCount('marketing_records', 0);

        // Themselves and a teammate: allowed.
        $own = $this->makeXarun($this->memberA, ['brought_by_admin_id' => $this->memberA->id]);
        $mate = $this->makeXarun($this->memberA, ['brought_by_admin_id' => $this->mateA->id]);
        $this->assertSame($this->mateA->id, MarketingRecord::query()->findOrFail($mate)->brought_by_admin_id);
        $this->assertSame($this->memberA->id, MarketingRecord::query()->findOrFail($own)->brought_by_admin_id);

        // No value: the creator, as before.
        $default = $this->makeXarun($this->memberA);
        $this->assertSame($this->memberA->id, MarketingRecord::query()->findOrFail($default)->brought_by_admin_id);
    }

    public function test_the_marketing_manager_may_register_a_lead_on_behalf_of_any_employee(): void
    {
        $xarun = $this->makeXarun($this->manager, ['brought_by_admin_id' => $this->memberB->id]);
        $this->assertSame($this->memberB->id, MarketingRecord::query()->findOrFail($xarun)->brought_by_admin_id);

        $project = $this->as($this->manager)->postJson('/api/v1/admin/marketing/project', ['responsible_party_type' => 'owner', 'brought_by_admin_id' => $this->memberA->id])->assertCreated()->json('data.id');
        $this->assertSame($this->memberA->id, MarketingRecord::query()->findOrFail($project)->brought_by_admin_id);
    }

    public function test_a_commission_rate_can_be_set_for_one_employee_by_the_manager_only(): void
    {
        $payload = ['admin_id' => $this->memberA->id, 'rate_type' => 'percentage', 'rate_value' => 5, 'effective_from' => '2026-08-01'];

        $this->as($this->manager)->postJson('/api/v1/admin/marketing/commission/rates', $payload)->assertCreated()->assertJsonPath('data.admin_id', $this->memberA->id);
        $this->as($this->memberA)->postJson('/api/v1/admin/marketing/commission/rates', $payload)->assertForbidden();
    }
}
