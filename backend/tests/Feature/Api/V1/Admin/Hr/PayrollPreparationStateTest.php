<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\PayrollItem;
use App\Models\PayrollRun;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * The October payroll was marked "Reviewed" by mistake and the owner wants it back in the state before review. The application's workflow is
 * draft -> reviewed -> approved -> finalized, and "reopen" exists only for an APPROVED run. These tests pin, on local data only, what the
 * workflow allows: the preparation state is "draft"; marking a run reviewed changes the run's status and reviewer stamps and nothing else (no
 * amount, day count, deduction, assignment or payment); and a reviewed run cannot be sent back without approving it first.
 */
class PayrollPreparationStateTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private const P = '2026-10';

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-21 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array<int, array<string, mixed>> every payroll line as stored, by id */
    private function lines(): array
    {
        return PayrollItem::query()->orderBy('id')->get()->map->toArray()->keyBy('id')->all();
    }

    private function newOctoberRun(string $token): PayrollRun
    {
        $employee = $this->makeEmployee('active', 'permanent');
        $this->makeAssignment($employee, $this->makeCompany('Alpha Hospital'), null, 300, '2026-01-01');
        $id = $this->withToken($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => self::P])->assertCreated()->json('data.id');

        return PayrollRun::query()->findOrFail($id);
    }

    public function test_a_new_run_is_draft_and_marking_it_reviewed_changes_only_the_status_and_the_reviewer_stamps(): void
    {
        [, $token] = $this->superAdminToken();
        $run = $this->newOctoberRun($token);

        $this->assertSame(PayrollRun::STATUS_DRAFT, $run->status, 'the state before review is draft');
        $this->assertNull($run->reviewed_by);
        $this->assertNull($run->reviewed_at);
        $before = ['lines' => $this->lines(), 'totals' => $run->totals, 'period' => $run->period];

        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk()->assertJsonPath('data.status', 'reviewed');

        $after = $run->fresh();
        $this->assertSame($before['lines'], $this->lines(), 'no amount, day count, deduction, assignment or payment line changed');
        $this->assertEquals($before['totals'], $after->totals);
        $this->assertSame($before['period'], $after->period);
        $this->assertNotNull($after->reviewed_by);
        $this->assertNull($after->approved_by, 'nothing was approved');
        $this->assertNull($after->finalized_by, 'nothing was finalized');
    }

    public function test_a_reviewed_run_cannot_be_sent_back_by_the_workflow_without_approving_it_first(): void
    {
        [, $token] = $this->superAdminToken();
        $run = $this->newOctoberRun($token);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk();
        $lines = $this->lines();

        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/reopen", ['reason' => 'Reviewed by mistake'])
            ->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_NOT_REOPENABLE');

        $this->assertSame(PayrollRun::STATUS_REVIEWED, $run->fresh()->status, 'still reviewed: the refusal changed nothing');
        $this->assertSame($lines, $this->lines());
    }

    public function test_a_run_put_back_to_its_pre_review_values_behaves_like_a_run_that_was_never_reviewed(): void
    {
        [, $token] = $this->superAdminToken();
        $run = $this->newOctoberRun($token);
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk();
        $lines = $this->lines();

        // The three values that "review" wrote - and only those - are put back (this is the local stand-in for the later, separately approved
        // production correction; it runs on the throw-away test database only).
        PayrollRun::query()->whereKey($run->id)->update(['status' => PayrollRun::STATUS_DRAFT, 'reviewed_at' => null, 'reviewed_by' => null]);

        $restored = $run->fresh();
        $this->assertSame(PayrollRun::STATUS_DRAFT, $restored->status);
        $this->assertNull($restored->reviewed_at);
        $this->assertNull($restored->reviewed_by);
        $this->assertSame($lines, $this->lines(), 'the payroll lines are exactly as they were');
        // From draft the normal workflow works again, and approval is still a separate, later step.
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/approve")->assertStatus(422)->assertJsonPath('error_code', 'PAYROLL_RUN_WRONG_STATE');
        $this->withToken($token)->patchJson("/api/v1/admin/hr/payroll-runs/{$run->id}/review")->assertOk()->assertJsonPath('data.status', 'reviewed');
    }
}
