<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\ClientCompany;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollItem;
use App\Models\PayrollRunSection;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision O2 (2026-11-07): Review and Finalize refuse a company section that has no valid stored snapshot (one built before snapshot protection, or by
 * an older version), for every role, with an answer that says what to do (Generate / refresh draft). Generate stores the snapshot, after which the existing
 * freshness guard judges the draft as before. Finalized payroll and payments are never touched. Synthetic data only.
 */
class PayrollSnapshotRequiredTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-10';

    private ClientCompany $alpha;

    private EmployeeWorkAssignment $annAssignment;

    private string $super;

    private string $hr;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-11-05 10:00:00');
        [, $this->super] = $this->superAdminToken();
        $this->hr = $this->tokenForRoleWith(AdminRole::HrManager, ['hr.view', 'hr.manage', 'hr.payroll.view', 'hr.payroll.manage', 'hr.payroll.approve']);

        $this->alpha = $this->makeCompany('Alpha Co');
        $ann = $this->makeEmployee();
        $bob = $this->makeEmployee();
        $this->annAssignment = $this->makeAssignment($ann, $this->alpha, null, 300, '2026-01-01');
        $this->makeAssignment($bob, $this->alpha, null, 200, '2026-01-01');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    private function generate(): void
    {
        $this->as($this->super)->json('POST', '/api/v1/admin/hr/payroll/'.self::P."/companies/{$this->alpha->id}/generate")->assertCreated();
    }

    private function section(): PayrollRunSection
    {
        return PayrollRunSection::query()->whereHas('run', fn ($q) => $q->where('period', self::P))->where('client_company_id', $this->alpha->id)->firstOrFail();
    }

    private function review(?string $token = null)
    {
        return $this->as($token ?? $this->hr)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section()->id.'/review');
    }

    private function finalize(?string $token = null)
    {
        return $this->as($token ?? $this->hr)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section()->id.'/finalize');
    }

    /** A section the way every one built before snapshot protection looks: lines exist, no snapshot. */
    private function makeLegacy(?string $status = null): void
    {
        $this->generate();
        $fields = ['generation_inputs' => null, 'generated_at' => null];

        if ($status === 'approved') {
            $fields += ['status' => 'approved', 'approved_at' => now(), 'approved_by' => null];
        } elseif ($status === 'reviewed') {
            $fields += ['status' => 'reviewed', 'reviewed_at' => now(), 'reviewed_by' => null];
        }

        PayrollRunSection::query()->whereKey($this->section()->id)->update($fields);
    }

    private function assertRefusedAsMissing($response): void
    {
        $response->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SNAPSHOT_MISSING')
            ->assertJsonPath('errors.required_action', 'generate')
            ->assertJsonPath('errors.period', self::P)
            ->assertJsonPath('errors.company_id', $this->alpha->id);
        $this->assertStringContainsString('Generate / refresh draft', (string) $response->json('message'));
        $this->assertStringContainsString('Alpha Co', (string) $response->json('message'));
        $this->assertStringContainsString('Nothing was changed', (string) $response->json('message'));
    }

    public function test_a_draft_without_a_snapshot_cannot_be_reviewed_and_nothing_changes(): void
    {
        $this->makeLegacy();
        $lines = PayrollItem::query()->where('section_id', $this->section()->id)->orderBy('id')->pluck('net_cents', 'id')->all();
        $before = $this->section()->only(['status', 'reviewed_at', 'reviewed_by', 'totals']);

        $this->assertRefusedAsMissing($this->review());

        $this->assertSame($before, $this->section()->only(['status', 'reviewed_at', 'reviewed_by', 'totals']));
        $this->assertSame($lines, PayrollItem::query()->where('section_id', $this->section()->id)->orderBy('id')->pluck('net_cents', 'id')->all());
    }

    public function test_a_draft_cannot_be_finalized_at_all_and_a_reviewed_one_without_a_snapshot_is_refused(): void
    {
        $this->makeLegacy();
        $this->finalize()->assertStatus(422);
        $this->assertSame('draft', $this->section()->status);

        $this->makeLegacy('reviewed');
        $this->assertRefusedAsMissing($this->finalize());
        $this->assertSame('reviewed', $this->section()->status);
    }

    public function test_a_reviewed_or_legacy_approved_section_without_a_snapshot_cannot_be_finalized(): void
    {
        foreach (['reviewed', 'approved'] as $status) {
            $this->makeLegacy($status);
            $this->assertRefusedAsMissing($this->finalize());
            $this->assertSame($status, $this->section()->status, 'the refusal leaves the status as it was');
            $this->assertNull($this->section()->finalized_at);
            PayrollRunSection::query()->whereKey($this->section()->id)->update(['status' => 'draft']);
        }
    }

    public function test_the_super_admin_is_refused_too_it_is_not_a_role_rule(): void
    {
        $this->makeLegacy();

        $this->assertRefusedAsMissing($this->review($this->super));

        $this->makeLegacy('reviewed');
        $this->assertRefusedAsMissing($this->finalize($this->super));
    }

    public function test_a_snapshot_of_another_version_or_period_counts_as_missing(): void
    {
        $this->generate();
        $snapshot = $this->section()->generation_inputs;

        foreach ([['v' => 0] + $snapshot, ['period' => '2026-09'] + $snapshot, ['v' => null] + $snapshot, []] as $bad) {
            PayrollRunSection::query()->whereKey($this->section()->id)->update(['generation_inputs' => json_encode($bad)]);
            $this->assertRefusedAsMissing($this->review());
        }
    }

    public function test_generate_stores_the_snapshot_and_then_review_and_finalize_work(): void
    {
        $this->makeLegacy();
        $this->assertRefusedAsMissing($this->review());

        $this->generate();

        $this->assertIsArray($this->section()->generation_inputs);
        $this->assertNotNull($this->section()->generated_at);
        $this->review()->assertOk();
        $this->finalize()->assertOk();
        $this->assertSame('finalized', $this->section()->status);
    }

    public function test_a_legacy_approved_section_is_reopened_regenerated_and_then_finalizes(): void
    {
        $this->makeLegacy('approved');
        $this->assertRefusedAsMissing($this->finalize());

        $this->as($this->hr)->patchJson('/api/v1/admin/hr/payroll-run-sections/'.$this->section()->id.'/reopen', ['reason' => 'Rebuild with snapshot'])->assertOk();
        $this->assertSame('draft', $this->section()->status);
        $this->generate();
        $this->review()->assertOk();
        $this->finalize()->assertOk();
    }

    public function test_a_stale_snapshot_is_still_a_different_answer_from_a_missing_one(): void
    {
        $this->generate();
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $this->review()->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SOURCES_CHANGED');
        $this->assertSame('draft', $this->section()->status);

        $this->generate();
        $this->review()->assertOk();
    }

    public function test_a_reviewed_company_whose_data_changed_still_cannot_be_finalized(): void
    {
        $this->generate();
        $this->review()->assertOk();
        $this->annAssignment->forceFill(['end_date' => '2026-10-20'])->save();

        $this->finalize()->assertStatus(409)->assertJsonPath('error_code', 'PAYROLL_SOURCES_CHANGED');
        $this->assertSame('reviewed', $this->section()->status);
    }

    public function test_a_finalized_company_without_a_snapshot_keeps_its_history_and_can_still_be_paid(): void
    {
        $this->generate();
        $this->review()->assertOk();
        $this->finalize()->assertOk();
        $lines = PayrollItem::query()->where('section_id', $this->section()->id)->orderBy('id')->pluck('net_cents', 'id')->all();
        // The 23 finalized October companies of production look like this: finalized, no snapshot.
        PayrollRunSection::query()->whereKey($this->section()->id)->update(['generation_inputs' => null, 'generated_at' => null]);

        $this->approvePaid($this->section()->id, $this->super)->assertSuccessful();

        $this->assertSame('finalized', $this->section()->status);
        $this->assertSame($lines, PayrollItem::query()->where('section_id', $this->section()->id)->orderBy('id')->pluck('net_cents', 'id')->all());
    }

    public function test_there_is_no_month_wide_review_or_finalize_to_go_around_the_check(): void
    {
        $this->makeLegacy();
        $run = $this->section()->run;

        foreach (['review', 'finalize'] as $step) {
            $this->assertGreaterThanOrEqual(400, $this->as($this->hr)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/{$step}")->getStatusCode());
        }

        $this->assertSame('draft', $this->section()->status);
    }
}
