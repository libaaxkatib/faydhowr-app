<?php

namespace Tests\Feature\Api\V1\Admin\Marketing;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\MarketingRecord;
use App\Models\MarketingTeam;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Focused regression coverage for the Phase 1 Marketing gaps fixed against
 * the audit at plans/marketing-implementation-audit-glimmering-island.md:
 * employee assignment, reassignment, team membership, quotation status,
 * and the new follow-up feedback/status actions. Not an attempt at full
 * Marketing test coverage — see the audit's "Automated test coverage"
 * finding for the acknowledged wider gap.
 */
class MarketingPhase1Test extends TestCase
{
    use RefreshDatabase;

    private function actingToken(): string
    {
        $admin = Admin::factory()->superAdmin()->create();

        return $admin->createToken('t')->plainTextToken;
    }

    public function test_xarun_can_be_created_with_an_assigned_marketing_employee(): void
    {
        $token = $this->actingToken();
        $team = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $employee = Admin::factory()->create(['role' => AdminRole::MarketingEmployee]);

        $response = $this->withToken($token)->postJson('/api/v1/admin/marketing/xarun', [
            'facility_name' => 'Test Facility',
            'assigned_team_id' => $team->id,
            'assigned_admin_id' => $employee->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.assigned_admin_id', $employee->id)
            ->assertJsonPath('data.assigned_admin_name', $employee->full_name)
            ->assertJsonPath('data.assigned_team_id', $team->id);

        $this->assertDatabaseHas('marketing_records', [
            'record_number' => $response->json('data.record_number'),
            'assigned_admin_id' => $employee->id,
            'assigned_team_id' => $team->id,
        ]);
    }

    public function test_project_can_be_created_with_an_assigned_marketing_employee(): void
    {
        $token = $this->actingToken();
        $employee = Admin::factory()->create(['role' => AdminRole::MarketingManager]);

        $response = $this->withToken($token)->postJson('/api/v1/admin/marketing/project', [
            'responsible_party_type' => 'engineer',
            'responsible_person_name' => 'Ahmed',
            'assigned_admin_id' => $employee->id,
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.assigned_admin_id', $employee->id)
            ->assertJsonPath('data.project.company_name', null);
    }

    public function test_reassignment_updates_team_and_employee_without_destroying_the_record_or_prior_audit_history(): void
    {
        $token = $this->actingToken();
        $originalTeam = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $newTeam = MarketingTeam::query()->where('name', 'Team B')->firstOrFail();
        $newEmployee = Admin::factory()->create(['role' => AdminRole::MarketingEmployee]);

        $created = $this->withToken($token)->postJson('/api/v1/admin/marketing/xarun', [
            'facility_name' => 'Reassign Facility',
            'assigned_team_id' => $originalTeam->id,
        ])->json('data');

        $creationAuditCount = AuditLog::query()->where('entity_type', MarketingRecord::class)->where('entity_id', $created['id'])->count();
        $this->assertSame(1, $creationAuditCount);

        $response = $this->withToken($token)->patchJson("/api/v1/admin/marketing/records/{$created['id']}/assign", [
            'assigned_team_id' => $newTeam->id,
            'assigned_admin_id' => $newEmployee->id,
        ]);

        $response->assertOk()
            ->assertJsonPath('data.id', $created['id'])
            ->assertJsonPath('data.assigned_team_id', $newTeam->id)
            ->assertJsonPath('data.assigned_admin_id', $newEmployee->id);

        // The original creation audit entry must still exist — reassignment appends, never overwrites history.
        $this->assertDatabaseHas('audit_logs', ['entity_type' => MarketingRecord::class, 'entity_id' => $created['id'], 'action' => 'create']);
        $this->assertDatabaseHas('audit_logs', ['entity_type' => MarketingRecord::class, 'entity_id' => $created['id'], 'action' => 'update']);
        $this->assertDatabaseCount('marketing_records', 1);
    }

    public function test_team_membership_can_be_added_and_removed_and_a_third_team_is_supported(): void
    {
        $token = $this->actingToken();
        $teamC = MarketingTeam::query()->create(['name' => 'Team C']);
        $employee = Admin::factory()->create(['role' => AdminRole::MarketingEmployee]);

        $this->withToken($token)
            ->postJson("/api/v1/admin/marketing/teams/{$teamC->id}/members", ['admin_id' => $employee->id])
            ->assertOk()
            ->assertJsonPath('data.members.0.id', $employee->id);

        $this->assertDatabaseHas('marketing_team_members', ['marketing_team_id' => $teamC->id, 'admin_id' => $employee->id]);

        $this->withToken($token)
            ->deleteJson("/api/v1/admin/marketing/teams/{$teamC->id}/members/{$employee->id}")
            ->assertOk()
            ->assertJsonCount(0, 'data.members');

        $this->assertDatabaseMissing('marketing_team_members', ['marketing_team_id' => $teamC->id, 'admin_id' => $employee->id]);
    }

    public function test_marketing_employees_endpoint_lists_only_active_marketing_roles(): void
    {
        $token = $this->actingToken();
        $team = MarketingTeam::query()->where('name', 'Team A')->firstOrFail();
        $manager = Admin::factory()->create(['role' => AdminRole::MarketingManager]);
        $inactiveEmployee = Admin::factory()->inactive()->create(['role' => AdminRole::MarketingEmployee]);
        $salesAdmin = Admin::factory()->create(['role' => AdminRole::Sales]);
        $team->members()->attach($manager->id);

        $response = $this->withToken($token)->getJson('/api/v1/admin/marketing/employees');

        $response->assertOk();
        $ids = collect($response->json('data'))->pluck('id');
        $this->assertTrue($ids->contains($manager->id));
        $this->assertFalse($ids->contains($inactiveEmployee->id));
        $this->assertFalse($ids->contains($salesAdmin->id));

        $managerEntry = collect($response->json('data'))->firstWhere('id', $manager->id);
        $this->assertSame('Team A', $managerEntry['teams'][0]['name']);
    }

    public function test_quotation_status_can_be_progressed_through_the_existing_endpoint(): void
    {
        $token = $this->actingToken();
        $created = $this->withToken($token)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Quote Facility'])->json('data');

        $quotation = $this->withToken($token)
            ->postJson("/api/v1/admin/marketing/records/{$created['id']}/quotations", ['amount' => 500])
            ->json('data');

        $this->assertSame('draft', $quotation['status']);

        $this->withToken($token)
            ->patchJson("/api/v1/admin/marketing/quotations/{$quotation['id']}/status", ['status' => 'sent'])
            ->assertOk()
            ->assertJsonPath('data.status', 'sent');

        $this->assertDatabaseHas('marketing_quotations', ['id' => $quotation['id'], 'status' => 'sent']);
    }

    public function test_follow_up_feedback_action_updates_record_feedback_and_logs_follow_up_history(): void
    {
        $token = $this->actingToken();
        $created = $this->withToken($token)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Feedback Facility'])->json('data');
        $followUp = $this->withToken($token)
            ->postJson("/api/v1/admin/marketing/records/{$created['id']}/follow-ups", ['follow_up_date' => now()->toDateString()])
            ->json('data');

        $response = $this->withToken($token)->patchJson("/api/v1/admin/marketing/follow-ups/{$followUp['id']}/feedback", [
            'feedback' => 'Customer wants a quote next week.',
        ]);

        $response->assertOk();

        $this->assertDatabaseHas('marketing_records', ['id' => $created['id'], 'feedback' => 'Customer wants a quote next week.']);
        $this->assertDatabaseHas('follow_up_histories', [
            'follow_up_id' => $followUp['id'],
            'action' => 'feedback_updated',
        ]);
    }

    public function test_follow_up_status_action_only_accepts_the_four_approved_statuses_and_logs_history(): void
    {
        $token = $this->actingToken();
        $created = $this->withToken($token)->postJson('/api/v1/admin/marketing/xarun', ['facility_name' => 'Status Facility'])->json('data');
        $followUp = $this->withToken($token)
            ->postJson("/api/v1/admin/marketing/records/{$created['id']}/follow-ups", ['follow_up_date' => now()->toDateString()])
            ->json('data');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/marketing/follow-ups/{$followUp['id']}/status", ['status' => 'won'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('status');

        $this->withToken($token)
            ->patchJson("/api/v1/admin/marketing/follow-ups/{$followUp['id']}/status", ['status' => 'quotation'])
            ->assertOk();

        $this->assertDatabaseHas('marketing_records', ['id' => $created['id'], 'status' => 'quotation']);
        $this->assertDatabaseHas('follow_up_histories', [
            'follow_up_id' => $followUp['id'],
            'action' => 'status_updated',
        ]);
    }

    public function test_follow_up_list_exposes_phone_location_and_history_for_reminder_actions(): void
    {
        $token = $this->actingToken();
        $created = $this->withToken($token)->postJson('/api/v1/admin/marketing/xarun', [
            'facility_name' => 'Contact Facility',
            'phone' => '+252611234567',
            'location' => 'Hodan',
        ])->json('data');
        $this->withToken($token)->postJson("/api/v1/admin/marketing/records/{$created['id']}/follow-ups", ['follow_up_date' => now()->toDateString()]);

        $response = $this->withToken($token)->getJson('/api/v1/admin/marketing/follow-ups?filter=all');

        $response->assertOk();
        $entry = collect($response->json('data'))->firstWhere('marketing_record_id', $created['id']);
        $this->assertSame('+252611234567', $entry['phone']);
        $this->assertSame('Hodan', $entry['location']);
        $this->assertNotEmpty($entry['histories']);
        $this->assertSame('created', $entry['histories'][0]['action']);
    }
}
