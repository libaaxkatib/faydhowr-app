<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AuditLog;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollCorrection;
use App\Models\PayrollItem;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FinalizesCompanies;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner decision PUT-END-DATE (2026-11-06): the PUT that edits an assignment refuses an end date earlier than the start date on the server, with the same
 * 422 as End Assignment (END_BEFORE_ASSIGNMENT_START), writes nothing when it refuses, and keeps every valid date edit working. Synthetic data only.
 */
class PutAssignmentEndDateTest extends TestCase
{
    use FinalizesCompanies;
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-10-20 10:00:00');
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

    public function test_an_end_date_before_the_stored_start_date_is_refused_even_when_the_request_sends_no_start_date(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Alpha Co'), null, 300, '2026-10-10');
        $before = $a->fresh()->getAttributes();
        $auditBefore = AuditLog::query()->count();

        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", ['end_date' => '2026-10-09'])
            ->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START')->assertJsonPath('errors.end_date.0', 'The last day cannot be before the assignment started.');
        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", ['end_date' => '2026-10-09', 'correction_reason' => 'Trying to force an earlier date'])
            ->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START');

        $this->assertSame($before, $a->fresh()->getAttributes(), 'no database write when the validation fails');
        $this->assertSame($auditBefore, AuditLog::query()->count(), 'no audit row either');
    }

    public function test_an_end_date_before_a_start_date_sent_in_the_same_request_is_refused(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Beta Co'), null, 300, '2026-10-10');
        $before = $a->fresh()->getAttributes();

        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", ['start_date' => '2026-10-15', 'end_date' => '2026-10-12'])->assertStatus(422);

        $this->assertSame($before, $a->fresh()->getAttributes());
    }

    public function test_moving_the_start_date_after_the_stored_end_date_is_refused_too(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Gamma Co'), null, 300, '2026-10-01');
        $a->update(['end_date' => '2026-10-10', 'status' => 'ended']);
        $before = $a->fresh()->getAttributes();

        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", ['start_date' => '2026-10-12'])
            ->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START');

        $this->assertSame($before, $a->fresh()->getAttributes());
    }

    public function test_valid_date_edits_and_edits_that_touch_neither_date_still_work(): void
    {
        [, $token] = $this->superAdminToken();
        $a = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Delta Co'), null, 300, '2026-10-10');

        // A note-only edit sends neither date, so it is not judged on the dates.
        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", ['notes' => 'Moved to the night desk'])->assertOk();
        $this->assertSame('Moved to the night desk', $a->fresh()->notes);

        // A planned end date moved LATER is a valid date edit (it ends nothing new, so no replacement choice is asked).
        $b = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Echo Co'), null, 300, '2026-10-10');
        $b->update(['end_date' => '2026-10-20']);
        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$b->id}", ['end_date' => '2026-10-25'])->assertOk();
        $this->assertSame('2026-10-25', $b->fresh()->end_date->toDateString());

        // An older record that is already inverted can still have its note edited, because that edit touches neither date.
        $c = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Foxtrot Co'), null, 300, '2026-10-10');
        $c->update(['end_date' => '2026-10-05']);
        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$c->id}", ['notes' => 'Legacy record, note only'])->assertOk();
    }

    // ------------------------------------------------------------------------------------------------ Final Remediation Audit: both directions, with closed payroll in the picture

    /** @return array{0: string, 1: EmployeeWorkAssignment} a September assignment (1-20 September) whose September payroll is finalized */
    private function assignmentInFinalizedSeptember(): array
    {
        [, $token] = $this->superAdminToken();
        $assignment = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Sept Co'), null, 300, '2026-09-01');
        $assignment->update(['end_date' => '2026-09-20']);
        $run = (int) $this->as($token)->postJson('/api/v1/admin/hr/payroll-runs', ['period' => '2026-09'])->assertCreated()->json('data.id');
        $this->finalizeEveryCompany($run, $token);

        return [$token, $assignment->refresh()];
    }

    public function test_both_directions_are_refused_before_any_correction_is_considered_and_nothing_is_written(): void
    {
        [$token, $a] = $this->assignmentInFinalizedSeptember();
        $before = $a->fresh()->getAttributes();
        $items = PayrollItem::query()->orderBy('id')->get()->map->getAttributes()->all();
        $corrections = PayrollCorrection::query()->count();
        $audit = AuditLog::query()->count();

        // Direction 1: an end date before the stored start. A correction reason does not turn it into a "correction".
        foreach ([['end_date' => '2026-08-31'], ['end_date' => '2026-08-31', 'correction_reason' => 'Ended before it began']] as $body) {
            $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", $body)->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START')->assertJsonPath('errors.end_date.0', 'The last day cannot be before the assignment started.');
        }
        // Direction 2: a start date moved after the stored end date.
        foreach ([['start_date' => '2026-09-25'], ['start_date' => '2026-09-25', 'correction_reason' => 'Started after it ended']] as $body) {
            $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", $body)->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START');
        }

        $this->assertSame($before, $a->fresh()->getAttributes());
        $this->assertSame($items, PayrollItem::query()->orderBy('id')->get()->map->getAttributes()->all(), 'the finalized September lines are untouched');
        $this->assertSame($corrections, PayrollCorrection::query()->count());
        $this->assertSame($audit, AuditLog::query()->count());
    }

    public function test_a_valid_date_edit_that_reaches_closed_payroll_still_needs_its_correction_reason(): void
    {
        [$token, $a] = $this->assignmentInFinalizedSeptember();

        // Moving the stored last day (20 September) to the 25th adds paid days to a finalized month: the existing correction rule applies, unchanged.
        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", ['end_date' => '2026-09-25'])->assertStatus(422)->assertJsonPath('error_code', 'CORRECTION_REASON_REQUIRED');
        $this->assertSame('2026-09-20', $a->fresh()->end_date->toDateString());

        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$a->id}", ['end_date' => '2026-09-25', 'correction_reason' => 'He worked until the 25th'])->assertOk();
        $this->assertSame('2026-09-25', $a->fresh()->end_date->toDateString());
        $this->assertGreaterThan(0, PayrollCorrection::query()->count(), 'recorded as a correction, never as a rewrite of the closed month');
    }

    public function test_end_assignment_keeps_its_own_validation_and_the_boundary_dates_are_valid(): void
    {
        [$token, $a] = $this->assignmentInFinalizedSeptember();
        $a->update(['end_date' => null]);

        $this->as($token)->patchJson("/api/v1/admin/hr/work-assignments/{$a->id}/end", ['end_date' => '2026-08-31'])->assertStatus(422)->assertJsonPath('error_code', 'END_BEFORE_ASSIGNMENT_START');

        // The first day is a valid last day on the PUT path too (a one-day assignment) - the comparison is strictly "earlier than".
        $b = $this->makeAssignment($this->makeEmployee(), $this->makeCompany('Edge Co'), null, 300, '2026-10-10');
        $b->update(['end_date' => '2026-10-12']);
        $this->as($token)->putJson("/api/v1/admin/hr/work-assignments/{$b->id}", ['end_date' => '2026-10-10'])->assertStatus(200);
        $this->assertSame('2026-10-10', $b->fresh()->end_date->toDateString());
    }
}
