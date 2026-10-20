<?php

namespace Tests\Feature\Api\V1\Admin\Hr;

use App\Enums\AdminRole;
use App\Models\Admin;
use App\Models\AuditLog;
use App\Models\ClientCompany;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\PayrollRun;
use App\Models\Permission;
use App\Models\Position;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\HrFixtures;
use Tests\TestCase;

/**
 * Owner requirement (2026-10-20): a company assignment records whether the person works the DAY or the NIGHT shift. The shift belongs to the
 * ASSIGNMENT (one person can work a day shift at one company and a night shift at another), is information only - it never changes salary,
 * dates, type or company - is NULL (unknown) for every assignment that never had one, and is authorised and audited like any assignment edit.
 */
class AssignmentShiftTest extends TestCase
{
    use HrFixtures;
    use RefreshDatabase;

    private string $super;

    private string $hr;

    private ClientCompany $a;

    private ClientCompany $b;

    private Position $position;

    protected function setUp(): void
    {
        parent::setUp();
        $this->super = $this->superAdminToken()[1];
        $this->hr = $this->tokenFor(AdminRole::HrManager, ['hr.view', 'hr.manage']);
        $this->a = $this->makeCompany('Shift Co A');
        $this->b = $this->makeCompany('Shift Co B');
        $this->position = Position::query()->create(['name' => 'Guard S']);
    }

    private function as(string $token): static
    {
        $this->app['auth']->forgetGuards();

        return $this->withToken($token);
    }

    /** @param list<string> $keys */
    private function tokenFor(AdminRole $role, array $keys): string
    {
        $admin = Admin::factory()->create(['role' => $role]);

        foreach ($keys as $key) {
            DB::table('admin_permissions')->insert(['admin_id' => $admin->id, 'permission_id' => Permission::query()->where('key', $key)->value('id'), 'created_at' => now(), 'updated_at' => now()]);
        }

        return $admin->createToken('t')->plainTextToken;
    }

    private function url(ClientCompany $c, string $tail = ''): string
    {
        return "/api/v1/admin/hr/client-companies/{$c->id}/employees{$tail}";
    }

    private function body(Employee $e, array $over = []): array
    {
        return array_replace(['employee_id' => $e->id, 'assignment_type' => 'permanent', 'position_id' => $this->position->id, 'start_date' => '2026-10-01', 'salary_amount' => 250, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'], $over);
    }

    private function raw(EmployeeWorkAssignment|int $a): array
    {
        return (array) DB::table('employee_work_assignments')->where('id', $a instanceof EmployeeWorkAssignment ? $a->id : $a)->first();
    }

    public function test_an_assignment_records_day_or_night_or_nothing_and_rejects_anything_else(): void
    {
        $day = $this->makeEmployee();
        $night = $this->makeEmployee();
        $none = $this->makeEmployee();

        $d = $this->as($this->hr)->postJson($this->url($this->a), $this->body($day, ['shift' => 'day']))->assertCreated()->assertJsonPath('data.shift', 'day')->json('data.id');
        $n = $this->as($this->hr)->postJson($this->url($this->a), $this->body($night, ['shift' => 'night']))->assertCreated()->assertJsonPath('data.shift', 'night')->json('data.id');
        $u = $this->as($this->hr)->postJson($this->url($this->a), $this->body($none))->assertCreated()->assertJsonPath('data.shift', null)->json('data.id');

        $this->assertSame(['day', 'night', null], [$this->raw($d)['shift'], $this->raw($n)['shift'], $this->raw($u)['shift']]);

        foreach (['evening', 'DAY ', 'both', 1] as $bad) {
            $this->as($this->hr)->postJson($this->url($this->a), $this->body($this->makeEmployee(), ['shift' => $bad]))->assertStatus(422)->assertJsonValidationErrors('shift');
        }

        // The list and the history show it; unknown stays unknown.
        $rows = collect($this->as($this->hr)->getJson($this->url($this->a))->assertOk()->json('data.employees'))->keyBy('assignment_id');
        $this->assertSame(['day', 'night', null], [$rows[$d]['shift'], $rows[$n]['shift'], $rows[$u]['shift']]);
        $history = collect($this->as($this->hr)->getJson("/api/v1/admin/hr/client-companies/{$this->a->id}/employee-history")->assertOk()->json('data.chain'))->keyBy('assignment_id');
        $this->assertSame('night', $history[$n]['shift']);

        // The audit event of the creation names the shift.
        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $n)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'assigned');
        $this->assertSame('night', $log->metadata['shift']);
    }

    public function test_the_database_itself_refuses_an_unknown_shift_on_postgresql_and_never_backfills_older_assignments(): void
    {
        $e = $this->makeEmployee();
        $old = $this->makeAssignment($e, $this->a);
        $this->assertNull($this->raw($old)['shift'], 'an assignment created without a shift stays unknown (nothing is assumed)');

        if (DB::getDriverName() === 'pgsql') {
            try {
                DB::transaction(fn () => DB::table('employee_work_assignments')->where('id', $old->id)->update(['shift' => 'evening']));
                $this->fail('PostgreSQL must refuse an unknown shift.');
            } catch (QueryException $e2) {
                $this->assertStringContainsString('employee_work_assignments_shift_check', $e2->getMessage());
            }
        }
    }

    public function test_editing_the_shift_changes_only_the_shift_is_audited_and_needs_no_payroll_correction(): void
    {
        $e = $this->makeEmployee();
        $assignment = $this->makeAssignment($e, $this->a, null, 250, '2026-09-01');
        // A finalized payroll month exists: a pay-changing edit would need a Super Admin correction, a shift edit must not.
        PayrollRun::query()->create(['period' => '2026-09', 'status' => 'finalized']);
        $before = $this->raw($assignment);

        $this->as($this->hr)->putJson($this->url($this->a, "/{$assignment->id}"), ['shift' => 'night'])->assertOk()->assertJsonPath('data.shift', 'night');

        $after = $this->raw($assignment);
        $changed = array_keys(array_diff_assoc($after, $before));
        sort($changed);
        $this->assertSame(['shift'], array_values(array_diff($changed, ['updated_at'])), 'only the shift (and at most its timestamp) changed: salary, dates, type, company, position and notes are identical');

        $log = AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $assignment->id)->get()->first(fn ($l) => ($l->metadata['event'] ?? null) === 'edited');
        $this->assertSame([['shift'], ['shift' => null], ['shift' => 'night']], [$log->metadata['changed_fields'], $log->metadata['before'], $log->metadata['after']]);
        $this->assertNotNull($log->admin_id);

        // It can be changed again and cleared back to "not recorded"; each change is audited.
        $this->as($this->hr)->putJson($this->url($this->a, "/{$assignment->id}"), ['shift' => 'day'])->assertOk()->assertJsonPath('data.shift', 'day');
        $this->as($this->hr)->putJson($this->url($this->a, "/{$assignment->id}"), ['shift' => null])->assertOk()->assertJsonPath('data.shift', null);
        $this->assertSame(3, AuditLog::query()->where('entity_type', EmployeeWorkAssignment::class)->where('entity_id', $assignment->id)->get()->filter(fn ($l) => ($l->metadata['event'] ?? null) === 'edited')->count());

        // The history shows the change (events carry before/after).
        $events = $this->as($this->hr)->getJson("/api/v1/admin/hr/client-companies/{$this->a->id}/employee-history")->assertOk()->json('data.events');
        $this->assertContains('night', array_map(fn ($e) => $e['details']['after']['shift'] ?? null, $events));

        // An invalid value changes nothing.
        $this->as($this->hr)->putJson($this->url($this->a, "/{$assignment->id}"), ['shift' => 'dusk'])->assertStatus(422);
        $this->assertNull($this->raw($assignment)['shift']);
    }

    public function test_only_users_who_may_manage_hr_can_set_or_change_a_shift(): void
    {
        $e = $this->makeEmployee();
        $assignment = $this->makeAssignment($e, $this->a);
        $refused = [
            'hr_viewer' => $this->tokenFor(AdminRole::Sales, ['hr.view']),
            'marketing_manager' => $this->tokenFor(AdminRole::MarketingManager, ['marketing.view', 'marketing.manage', 'marketing.assign']),
            'mobile_app_manager' => $this->tokenFor(AdminRole::MobileAppManager, ['dashboard.view', 'customers.view']),
        ];

        foreach ($refused as $who => $token) {
            $this->as($token)->putJson($this->url($this->a, "/{$assignment->id}"), ['shift' => 'night'])->assertForbidden();
            $this->as($token)->postJson($this->url($this->a), $this->body($this->makeEmployee(), ['shift' => 'night']))->assertForbidden();
        }

        $this->app['auth']->forgetGuards();
        $this->flushHeaders();
        $this->putJson($this->url($this->a, "/{$assignment->id}"), ['shift' => 'night'])->assertUnauthorized();
        $this->assertNull($this->raw($assignment)['shift'], 'a refused caller changed nothing');

        $this->as($this->super)->putJson($this->url($this->a, "/{$assignment->id}"), ['shift' => 'night'])->assertOk();
        $this->assertSame('night', $this->raw($assignment)['shift']);

        // The assignment of another company cannot be reached through this company's URL.
        $this->as($this->super)->putJson($this->url($this->b, "/{$assignment->id}"), ['shift' => 'day'])->assertNotFound();
        $this->assertSame('night', $this->raw($assignment)['shift']);
    }

    public function test_replace_and_return_carry_the_shift_of_the_position_unless_hr_chooses_another(): void
    {
        $first = $this->makeEmployee();
        $second = $this->makeEmployee();
        $third = $this->makeEmployee();
        $original = $this->as($this->hr)->postJson($this->url($this->a), $this->body($first, ['shift' => 'night']))->assertCreated()->json('data.id');

        // Replace inherits the night shift of the position ...
        $repl = $this->as($this->hr)->postJson($this->url($this->a, "/{$original}/replace"), ['replacement_employee_id' => $second->id, 'replacement_date' => '2026-10-15', 'assignment_type' => 'replacement'])->assertOk();
        $replacementId = $repl->json('data.replacement.id');
        $this->assertSame('night', $repl->json('data.replacement.shift'));
        $this->assertSame('night', $this->raw($original)['shift'], 'the ended assignment keeps its shift on record');

        // ... and Return gives the original employee the position's current shift unless another one is chosen.
        $ret = $this->as($this->hr)->postJson($this->url($this->a, "/{$replacementId}/return"), ['return_date' => '2026-10-25', 'salary_amount' => 250, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly'])->assertOk();
        $this->assertSame('night', $ret->json('data.returned.shift'));

        // HR can choose the shift explicitly on Replace.
        $x = $this->as($this->hr)->postJson($this->url($this->b), $this->body($third, ['shift' => 'day']))->assertCreated()->json('data.id');
        $y = $this->as($this->hr)->postJson($this->url($this->b, "/{$x}/replace"), ['replacement_employee_id' => $this->makeEmployee()->id, 'replacement_date' => '2026-10-15', 'assignment_type' => 'permanent', 'salary_amount' => 180, 'salary_currency' => 'USD', 'salary_frequency' => 'monthly', 'shift' => 'night'])->assertOk();
        $this->assertSame('night', $y->json('data.replacement.shift'));
        $this->assertSame('day', $this->raw($x)['shift']);

        // A salary or date of the replacement was not touched by the shift handling.
        $this->assertSame('180.00', (string) $y->json('data.replacement.salary_amount'));
    }

    public function test_one_person_can_work_two_companies_on_different_shifts_without_one_overwriting_the_other(): void
    {
        $e = $this->makeEmployee();
        $this->as($this->super)->putJson('/api/v1/admin/hr/policies/multi_company_assignments', ['enabled' => true, 'reason' => 'One employee works a night and a day shift at two companies'])->assertOk();

        $one = $this->as($this->hr)->postJson($this->url($this->a), $this->body($e, ['shift' => 'night', 'salary_amount' => 250]))->assertCreated()->json('data.id');
        $two = $this->as($this->hr)->postJson($this->url($this->b), $this->body($e, ['shift' => 'day', 'salary_amount' => 120]))->assertCreated()->json('data.id');
        $this->assertNotSame($one, $two);
        $beforeTwo = $this->raw($two);

        $rowsA = collect($this->as($this->hr)->getJson($this->url($this->a))->json('data.employees'))->firstWhere('assignment_id', $one);
        $rowsB = collect($this->as($this->hr)->getJson($this->url($this->b))->json('data.employees'))->firstWhere('assignment_id', $two);
        $this->assertSame(['night', 'day'], [$rowsA['shift'], $rowsB['shift']]);

        // Changing one assignment's shift never touches the other assignment of the same person.
        $this->as($this->hr)->putJson($this->url($this->a, "/{$one}"), ['shift' => 'day'])->assertOk();
        $this->assertSame($beforeTwo, $this->raw($two));
        $this->assertEqualsWithDelta([250.0, 120.0], [(float) $this->raw($one)['salary_amount'], (float) $this->raw($two)['salary_amount']], 0.001);
    }
}
