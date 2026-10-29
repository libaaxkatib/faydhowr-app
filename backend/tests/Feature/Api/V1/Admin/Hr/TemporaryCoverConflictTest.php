<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Models\AssignmentSalaryHistory;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\TemporaryReplacement;
use App\Models\WorkLocation;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * docs/HR_PAYROLL_RULES.md U12 and U1 (owner decisions of 2026-10-27). Everything goes through the API, so the rules are shown to hold for any caller:
 *
 *   U12  a Temporary cover that overlaps another Temporary cover of the same position, or of the same Temporary employee on another position, is
 *        refused; the two may share a calendar day only when both positions are on different RECORDED shifts.
 *   U1   a Temporary employee who already has an assignment of their own over the same days is refused, with the Somali warning; nothing is
 *        cancelled, replaced, re-typed or recalculated on HR's behalf.
 *
 * Dates are inclusive at both ends. Synthetic data only.
 */
class TemporaryCoverConflictTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        CarbonImmutable::setTestNow('2026-09-01 10:00:00');
    }

    protected function tearDown(): void
    {
        CarbonImmutable::setTestNow();
        parent::tearDown();
    }

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} a Permanent employee holding a position, optionally on a recorded shift */
    private function position(?string $shift = null): array
    {
        $permanent = $this->makeEmployee('active', 'permanent');
        $assignment = $this->makeAssignment($permanent, $this->makeCompany(fake()->unique()->company()), null, 300, '2026-01-01');
        $assignment->update(['shift' => $shift]);

        return [$permanent, $assignment];
    }

    private function makeWorkLocation(): WorkLocation
    {
        $company = ClientCompany::query()->create(['name' => fake()->unique()->company(), 'status' => 'active']);

        return WorkLocation::query()->create(['client_company_id' => $company->id, 'location_type' => 'client', 'name' => 'Site '.fake()->unique()->numerify('###'), 'status' => 'active']);
    }

    private function temporary(): Employee
    {
        return $this->makeEmployee('active', 'temporary');
    }

    private function create(string $token, EmployeeWorkAssignment $position, Employee $temp, string $start, ?string $end = null): TestResponse
    {
        return $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', [
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $temp->id, 'start_date' => $start, 'end_date' => $end,
        ]);
    }

    private function end(string $token, TemporaryReplacement $cover, string $date, bool $confirmExtension = false): TestResponse
    {
        return $this->withToken($token)->patchJson("/api/v1/admin/hr/temporary-replacements/{$cover->id}/end", ['end_date' => $date] + ($confirmExtension ? ['confirm_extension' => true] : []));
    }

    private function saved(string $token, EmployeeWorkAssignment $position, Employee $temp, string $start, ?string $end = null): TemporaryReplacement
    {
        return TemporaryReplacement::query()->findOrFail($this->create($token, $position, $temp, $start, $end)->assertCreated()->json('data.id'));
    }

    // ----------------------------------------------------------------------------------------------- U12: the same position

    public function test_a_cover_overlapping_another_cover_of_the_same_position_is_refused_and_names_the_conflicting_cover(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $first = $this->saved($token, $position, $this->temporary(), '2026-09-10', '2026-09-20');

        $response = $this->create($token, $position, $this->temporary(), '2026-09-15', '2026-09-25')
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION');

        $this->assertStringContainsString("cover #{$first->id}", $response->json('message'));
        $this->assertSame([$first->id, '2026-09-10', '2026-09-20'], [$response->json('errors.conflicts.0.id'), $response->json('errors.conflicts.0.start_date'), $response->json('errors.conflicts.0.end_date')]);
        $this->assertSame(1, TemporaryReplacement::query()->count(), 'nothing was saved');
    }

    public function test_a_position_never_accepts_two_covers_at_once_even_on_a_recorded_shift(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position('day');
        $this->saved($token, $position, $this->temporary(), '2026-09-10', '2026-09-20');

        $this->create($token, $position, $this->temporary(), '2026-09-12', '2026-09-14')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION');
    }

    public function test_an_ended_cover_still_blocks_the_days_it_covered(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $first = $this->saved($token, $position, $this->temporary(), '2026-09-10');
        $this->end($token, $first, '2026-09-20')->assertOk();

        $this->create($token, $position, $this->temporary(), '2026-09-18', '2026-09-22')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION');
        $this->create($token, $position, $this->temporary(), '2026-09-21', '2026-09-22')->assertCreated();
    }

    public function test_an_open_ended_cover_blocks_every_later_start_until_it_is_ended(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $open = $this->saved($token, $position, $this->temporary(), '2026-09-10');

        $this->create($token, $position, $this->temporary(), '2027-03-01', '2027-03-05')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION');
        // ... and a cover that ends before the open one begins is fine.
        $this->create($token, $position, $this->temporary(), '2026-09-01', '2026-09-09')->assertCreated();

        $this->end($token, $open, '2026-09-30')->assertOk();
        $this->create($token, $position, $this->temporary(), '2026-10-01', '2026-10-05')->assertCreated();
    }

    // ----------------------------------------------------------------------------------------------- inclusive boundaries

    public function test_both_boundaries_are_inclusive(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $this->saved($token, $position, $this->temporary(), '2026-09-10', '2026-09-20');

        // Starting ON the other's last day, or ending ON its first day, shares that day.
        $this->create($token, $position, $this->temporary(), '2026-09-20', '2026-09-25')->assertStatus(422);
        $this->create($token, $position, $this->temporary(), '2026-09-05', '2026-09-10')->assertStatus(422);
        // The day after / the day before shares nothing.
        $this->create($token, $position, $this->temporary(), '2026-09-21', '2026-09-25')->assertCreated();
        $this->create($token, $position, $this->temporary(), '2026-09-05', '2026-09-09')->assertCreated();
        // A one-day cover on the boundary is an overlap too.
        $this->create($token, $position, $this->temporary(), '2026-09-20', '2026-09-20')->assertStatus(422);
        $this->assertSame(3, TemporaryReplacement::query()->count());
    }

    public function test_consecutive_periods_of_one_position_are_allowed(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();

        foreach ([['2026-09-01', '2026-09-10'], ['2026-09-11', '2026-09-20'], ['2026-09-21', '2026-09-30']] as [$start, $end]) {
            $this->create($token, $position, $this->temporary(), $start, $end)->assertCreated();
        }

        $this->assertSame(3, TemporaryReplacement::query()->count());
    }

    // ----------------------------------------------------------------------------------------------- U12: the same employee

    public function test_the_same_temporary_cannot_cover_two_positions_over_the_same_days(): void
    {
        [, $token] = $this->superAdminToken();
        [, $positionA] = $this->position();
        [, $positionB] = $this->position();
        $temp = $this->temporary();
        $first = $this->saved($token, $positionA, $temp, '2026-09-10', '2026-09-20');

        $response = $this->create($token, $positionB, $temp, '2026-09-18', '2026-09-25')
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE');
        $this->assertSame($first->id, $response->json('errors.conflicts.0.id'));
        $this->assertStringContainsString("cover #{$first->id}", $response->json('message'));
        $this->assertSame(1, TemporaryReplacement::query()->count());

        // The day after the first ends, the same person may cover the other position.
        $this->create($token, $positionB, $temp, '2026-09-21', '2026-09-25')->assertCreated();
    }

    public function test_the_same_temporary_may_cover_two_positions_on_the_same_day_when_their_recorded_shifts_differ(): void
    {
        [, $token] = $this->superAdminToken();
        [, $dayPosition] = $this->position('day');
        [, $nightPosition] = $this->position('night');
        $temp = $this->temporary();

        $this->create($token, $dayPosition, $temp, '2026-09-10', '2026-09-20')->assertCreated();
        $this->create($token, $nightPosition, $temp, '2026-09-10', '2026-09-20')->assertCreated();
    }

    public function test_equal_or_unrecorded_shifts_are_never_assumed_to_differ(): void
    {
        [, $token] = $this->superAdminToken();
        $temp = $this->temporary();
        [, $day] = $this->position('day');
        [, $anotherDay] = $this->position('day');
        [, $unknown] = $this->position(null);
        [, $night] = $this->position('night');

        $this->create($token, $day, $temp, '2026-09-10', '2026-09-20')->assertCreated();
        $this->create($token, $anotherDay, $temp, '2026-09-10', '2026-09-20')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE');
        $this->create($token, $unknown, $temp, '2026-09-10', '2026-09-20')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE');
        $response = $this->create($token, $night, $temp, '2026-09-10', '2026-09-20')->assertCreated();
        // The unrecorded shift is also refused against the night cover now saved.
        $this->create($token, $unknown, $temp, '2026-09-15', '2026-09-16')->assertStatus(422);
        $this->assertSame(2, TemporaryReplacement::query()->count());
        $this->assertNotNull($response->json('data.id'));
    }

    // ----------------------------------------------------------------------------------------------- edits and the API

    public function test_extending_a_cover_into_free_days_is_allowed_because_the_edited_cover_is_never_its_own_conflict(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $first = $this->saved($token, $position, $this->temporary(), '2026-09-01', '2026-09-10');
        $this->saved($token, $position, $this->temporary(), '2026-09-21', '2026-09-30');

        // The cover being edited is never compared with itself: extending into free days works...
        // (a last day later than the recorded end lengthens the cover, so it is only accepted once the caller confirms the extension)
        $this->end($token, $first, '2026-09-15')->assertStatus(422)->assertJsonPath('error_code', 'EXTENSION_CONFIRMATION_REQUIRED');
        $this->assertSame('2026-09-10', $first->fresh()->end_date->toDateString());
        $this->end($token, $first, '2026-09-15', true)->assertOk();
        $this->assertSame('2026-09-15', $first->fresh()->end_date->toDateString());
    }

    public function test_changing_the_last_day_cannot_run_into_the_next_cover(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $first = $this->saved($token, $position, $this->temporary(), '2026-09-01', '2026-09-10');
        $second = $this->saved($token, $position, $this->temporary(), '2026-09-21', '2026-09-30');

        $this->end($token, $first, '2026-09-21')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION')->assertJsonPath('errors.conflicts.0.id', $second->id);
        $this->assertSame('2026-09-10', $first->fresh()->end_date->toDateString(), 'nothing was changed');
        $this->assertSame('active', $first->fresh()->status->value);

        $this->end($token, $first, '2026-09-20', true)->assertOk();
        // Shortening is always allowed: it cannot create an overlap.
        $this->end($token, $second, '2026-09-25')->assertOk();
    }

    public function test_the_api_cannot_be_used_to_get_around_the_rule(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $temp = $this->temporary();
        $this->saved($token, $position, $temp, '2026-09-10', '2026-09-20');
        $before = TemporaryReplacement::query()->count();

        // Different spellings of the same dates, an extra "daily_rate", an open end and a repeated request: all refused.
        foreach ([
            ['start_date' => '2026-09-10', 'end_date' => '2026-09-20'],
            ['start_date' => '2026-09-12', 'end_date' => null],
            ['start_date' => '2026-09-19T00:00:00Z', 'end_date' => '2026-09-19T23:59:59Z', 'daily_rate' => 99],
            ['start_date' => '2026-09-01', 'end_date' => '2026-09-30'],
        ] as $payload) {
            $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', $payload + ['work_assignment_id' => $position->id, 'replacement_employee_id' => $this->temporary()->id])
                ->assertStatus(422);
            $this->withToken($token)->postJson('/api/v1/admin/hr/temporary-replacements', $payload + ['work_assignment_id' => $position->id, 'replacement_employee_id' => $temp->id])
                ->assertStatus(422);
        }

        $this->assertSame($before, TemporaryReplacement::query()->count());
    }

    public function test_the_preview_says_what_the_save_would_say(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        $first = $this->saved($token, $position, $this->temporary(), '2026-09-10', '2026-09-20');
        $query = fn (string $start, string $end): TestResponse => $this->withToken($token)->getJson('/api/v1/admin/hr/temporary-replacements/conflict-check?'.http_build_query([
            'work_assignment_id' => $position->id, 'replacement_employee_id' => $this->temporary()->id, 'start_date' => $start, 'end_date' => $end,
        ]));

        $query('2026-09-15', '2026-09-25')->assertOk()->assertJsonPath('data.conflict.code', 'TEMPORARY_COVER_OVERLAPS_POSITION')->assertJsonPath('data.conflict.conflicts.0.id', $first->id);
        $query('2026-09-21', '2026-09-25')->assertOk()->assertJsonPath('data.conflict', null);
        $this->assertSame(1, TemporaryReplacement::query()->count(), 'the preview saves nothing');
    }

    // ----------------------------------------------------------------------------------------------- U1: the employee's own assignment

    /** @return array{0: Employee, 1: EmployeeWorkAssignment} a Temporary employee who also holds a Permanent assignment of their own */
    private function temporaryWithOwnAssignment(?string $shift, string $start = '2026-01-01', ?string $end = null): array
    {
        $temp = $this->temporary();
        $own = $this->makeAssignment($temp, $this->makeCompany(fake()->unique()->company()), null, 250, $start);
        $own->update(['shift' => $shift, 'end_date' => $end]);

        return [$temp, $own];
    }

    public function test_a_temporary_with_an_overlapping_permanent_assignment_gets_the_somali_warning_and_nothing_changes(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        [$temp, $own] = $this->temporaryWithOwnAssignment(null);
        $histories = AssignmentSalaryHistory::query()->count();

        $response = $this->create($token, $position, $temp, '2026-09-10', '2026-09-20')
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT');

        $this->assertStringStartsWith('Shaqaalahaan wuxuu hore ugu jiraa shaqo Permanent ah oo waqtigeedu isku dhacayo shaqadan.', $response->json('message'));
        $this->assertStringContainsString('jooji/cancel garee shaqadii hore, kadibna qof kale u qoondee booskii hore', $response->json('message'));
        $this->assertStringContainsString('Shift-yo kala duwan waa la oggol yahay haddii waqtigoodu isma saarna', $response->json('message'));
        $this->assertSame([$own->id, 'assignment'], [$response->json('errors.conflicts.0.id'), $response->json('errors.conflicts.0.kind')]);

        // Nothing was done on HR's behalf: the earlier assignment, the employee, the covers and the salary history are exactly as they were.
        $this->assertSame('active', $own->fresh()->status->value);
        $this->assertNull($own->fresh()->end_date);
        $this->assertSame('temporary', $temp->fresh()->employment_type);
        $this->assertSame(0, TemporaryReplacement::query()->count());
        $this->assertSame($histories, AssignmentSalaryHistory::query()->count());
        $this->assertSame(2, EmployeeWorkAssignment::query()->count());
    }

    public function test_when_no_shift_is_recorded_the_warning_says_what_to_correct(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position('night');
        [$temp] = $this->temporaryWithOwnAssignment(null);

        $message = $this->create($token, $position, $temp, '2026-09-10', '2026-09-20')->assertStatus(422)->json('message');

        $this->assertStringContainsString('lama qorin', $message);
        $this->assertStringContainsString('Fadlan ku qor shift-ka (Maalin/Habeen) labada shaqo', $message);
    }

    public function test_the_same_shift_is_a_clash_and_different_recorded_shifts_are_allowed(): void
    {
        [, $token] = $this->superAdminToken();
        [, $nightPosition] = $this->position('night');
        [$tempSame] = $this->temporaryWithOwnAssignment('night');
        [$tempOther] = $this->temporaryWithOwnAssignment('day');

        $this->create($token, $nightPosition, $tempSame, '2026-09-10', '2026-09-20')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT');
        $this->create($token, $nightPosition, $tempOther, '2026-09-10', '2026-09-20')->assertCreated();
    }

    public function test_an_own_assignment_that_does_not_reach_the_cover_days_does_not_clash(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        [$endedBefore] = $this->temporaryWithOwnAssignment(null, '2026-01-01', '2026-09-09');
        [$startsAfter] = $this->temporaryWithOwnAssignment(null, '2026-09-21');
        [$touching] = $this->temporaryWithOwnAssignment(null, '2026-01-01', '2026-09-10');

        $this->create($token, $position, $endedBefore, '2026-09-10', '2026-09-20')->assertCreated();
        $this->create($token, $this->position()[1], $startsAfter, '2026-09-10', '2026-09-20')->assertCreated();
        // The own assignment's last day is the cover's first day: inclusive, so that day is shared.
        $this->create($token, $this->position()[1], $touching, '2026-09-10', '2026-09-20')->assertStatus(422);
    }

    public function test_a_cancelled_assignment_does_not_clash(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position();
        [$temp, $own] = $this->temporaryWithOwnAssignment(null);
        $own->update(['status' => 'cancelled']);

        $this->create($token, $position, $temp, '2026-09-10', '2026-09-20')->assertCreated();
    }

    public function test_giving_a_covering_temporary_an_overlapping_assignment_is_refused_from_the_other_side(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position('day');
        $temp = $this->temporary();
        $this->saved($token, $position, $temp, '2026-09-10', '2026-09-20');
        $location = $this->makeWorkLocation();
        $payload = fn (array $extra): array => $extra + [
            'work_location_id' => $location->id, 'start_date' => '2026-09-15', 'salary_amount' => 100, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly',
        ];

        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $payload([]))
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT');
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $payload(['shift' => 'day']))
            ->assertStatus(422);
        $this->withToken($token)->postJson("/api/v1/admin/hr/employees/{$temp->id}/work-assignments", $payload(['shift' => 'night']))
            ->assertCreated();
    }

    public function test_changing_a_shift_cannot_create_an_overlap_and_editing_other_fields_never_conflicts_with_itself(): void
    {
        [, $token] = $this->superAdminToken();
        [, $dayPosition] = $this->position('day');
        [, $nightPosition] = $this->position('night');
        $temp = $this->temporary();
        $this->saved($token, $dayPosition, $temp, '2026-09-10', '2026-09-20');
        $this->saved($token, $nightPosition, $temp, '2026-09-10', '2026-09-20');

        // Editing a note of a covered position is not a conflict with its own cover.
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$dayPosition->id}", ['notes' => 'checked'])->assertOk();
        // Making the night position a day position would put one person on two day shifts at once.
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$nightPosition->id}", ['shift' => 'day'])
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_EMPLOYEE');
        $this->assertSame('night', $nightPosition->fresh()->shift->value);
    }

    public function test_editing_the_dates_of_the_persons_own_assignment_cannot_make_it_overlap_their_cover(): void
    {
        [, $token] = $this->superAdminToken();
        [, $dayPosition] = $this->position('day');
        [, $nightPosition] = $this->position('night');
        [$temp, $own] = $this->temporaryWithOwnAssignment('day', '2026-01-01', '2026-09-09');
        $this->saved($token, $dayPosition, $temp, '2026-09-10', '2026-09-20');

        // Stretching the earlier day assignment into the cover days puts one person on two day shifts at once: refused, nothing saved.
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$own->id}", ['end_date' => '2026-09-12'])
            ->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_EMPLOYEE_HAS_OVERLAPPING_ASSIGNMENT');
        $this->assertSame('2026-09-09', $own->fresh()->end_date->toDateString());

        // The same stretch is fine when the assignment is on the other shift, and a plain note edit never conflicts with itself.
        $own->update(['shift' => 'night']);
        $this->withToken($token)->putJson("/api/v1/admin/hr/work-assignments/{$own->id}", ['end_date' => '2026-09-12', 'notes' => 'moved'])->assertOk();
        $this->assertSame('2026-09-12', $own->fresh()->end_date->toDateString());
        $this->assertNotNull($nightPosition);
    }

    public function test_submitting_the_same_cover_twice_saves_it_once(): void
    {
        [, $token] = $this->superAdminToken();
        [, $position] = $this->position('day');
        $temp = $this->temporary();

        // The second request finds the first one saved (the position and the person are locked while checking and saving).
        $this->create($token, $position, $temp, '2026-09-10', '2026-09-20')->assertCreated();
        $this->create($token, $position, $temp, '2026-09-10', '2026-09-20')->assertStatus(422)->assertJsonPath('error_code', 'TEMPORARY_COVER_OVERLAPS_POSITION');

        $this->assertSame(1, TemporaryReplacement::query()->count());
    }
}
