<?php

namespace App\Support\Hr\OfficeStaff;

use App\Actions\Hr\CreateOrUpdateEmployeeGuarantorAction;
use App\Actions\Hr\CreateWorkAssignmentAction;
use App\Enums\AuditAction;
use App\Enums\LocationType;
use App\Events\Audit\AuditEvent;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\EmployeeWorkAssignment;
use App\Models\WorkLocation;
use App\Support\ApiResponse;
use App\Support\Hr\EmployeeDuplicateFinder;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The shared steps of putting an employee into Office Staff, used by both the "register new" and the "add
 * existing" paths so they can never drift apart:
 *
 *  - emergency contact -> the employee's existing secondary_contact_* columns (the same fields the employee
 *    page labels "Secondary/emergency contact"); never mixed with the guarantor;
 *  - guarantor -> the existing employee_guarantors row (Damiin), updated in place, never duplicated;
 *  - position -> the employee's position, and the new assignment's position;
 *  - salary -> a new work assignment at the Fayadhowr Office (monthly, USD) through the existing
 *    CreateWorkAssignmentAction, so office capacity and the duplicate-assignment rules still apply.
 *
 * Nothing existing is overwritten silently: a stored emergency contact, guarantor or position that differs
 * from what was submitted is only replaced when the caller confirmed that exact field. Saving a salary
 * creates an assignment and its salary history only - it never creates a payroll run or a payment.
 */
class OfficeStaffEnrolment
{
    public function __construct(
        private readonly CreateOrUpdateEmployeeGuarantorAction $guarantors,
        private readonly CreateWorkAssignmentAction $assignments,
    ) {}

    /**
     * @param  array<string, mixed>  $data  validated request data
     */
    public function enrol(Employee $employee, array $data, Admin $actor, bool $isNewEmployee): EmployeeWorkAssignment
    {
        $office = WorkLocation::query()->where('location_type', LocationType::Office->value)->first();

        if ($office === null) {
            $this->fail('The Fayadhowr Office location is not set up.', 'OFFICE_NOT_CONFIGURED');
        }

        $confirmed = $data['confirm_overwrite'] ?? [];

        if (! $isNewEmployee) {
            $this->guardExistingEmployee($employee, $data, $confirmed);
        }

        $this->saveEmergencyContact($employee, $data);
        $this->saveGuarantor($employee, $data, $actor);
        $this->savePosition($employee, $data, $confirmed);

        $assignment = $this->assignments->handle($employee, [
            'work_location_id' => $office->id,
            'position_id' => $data['position_id'],
            'start_date' => $data['start_date'],
            'salary_amount' => $data['monthly_salary'],
            'salary_currency' => 'USD',
            'salary_frequency' => 'monthly',
            'override_capacity' => (bool) ($data['override_capacity'] ?? false),
        ], $actor);

        // A brand-new employee already has its own "registered as Office Staff" audit entry from the create step.
        if (! $isNewEmployee) {
            event(AuditEvent::record(
                action: AuditAction::Update,
                admin: $actor,
                description: "Employee '{$employee->full_name}' ({$employee->employee_number}) added as Office Staff.",
                entityType: Employee::class,
                entityId: $employee->id,
                metadata: ['assignment_id' => $assignment->id],
            ));
        }

        return $assignment;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $confirmed
     */
    private function guardExistingEmployee(Employee $employee, array $data, array $confirmed): void
    {
        $summary = OfficeStaffSummary::for($employee);

        if ($summary['is_office_staff']) {
            $this->fail('This employee is already Office Staff.', 'ALREADY_OFFICE_STAFF');
        }

        if (! $summary['can_add']) {
            $this->fail((string) $summary['cannot_add_reason'], 'EMPLOYEE_NOT_ACTIVE');
        }

        // A second salary on top of an existing assignment is paid twice by payroll, so it must be a conscious choice.
        if ($summary['active_assignments'] !== [] && ! ($data['confirm_additional_assignment'] ?? false)) {
            $this->fail(
                'This employee already has an active assignment ('.collect($summary['active_assignments'])->map(fn (array $a): string => "{$a['label']}, {$a['salary']}")->implode('; ').'). Adding Office Staff creates a second assignment with its own salary, and payroll pays both. Confirm to continue.',
                'ADDITIONAL_ASSIGNMENT_NOT_CONFIRMED',
                ['assignments' => $summary['active_assignments']],
            );
        }

        $conflicts = [];

        if ($this->filled($employee->secondary_contact_name) || $this->filled($employee->secondary_contact_phone)) {
            $differs = ! $this->sameText($employee->secondary_contact_name, $data['emergency_contact_name'])
                || EmployeeDuplicateFinder::phoneKey($employee->secondary_contact_phone) !== EmployeeDuplicateFinder::phoneKey($data['emergency_contact_phone']);

            if ($differs && ! in_array('emergency_contact', $confirmed, true)) {
                $conflicts['emergency_contact'] = ['name' => $employee->secondary_contact_name, 'phone' => $employee->secondary_contact_phone];
            }
        }

        $guarantor = $employee->guarantor;
        if ($guarantor !== null) {
            $differs = ! $this->sameText($guarantor->guarantor_name, $data['guarantor_name'])
                || EmployeeDuplicateFinder::phoneKey($guarantor->guarantor_phone) !== EmployeeDuplicateFinder::phoneKey($data['guarantor_phone']);

            if ($differs && ! in_array('guarantor', $confirmed, true)) {
                $conflicts['guarantor'] = ['name' => $guarantor->guarantor_name, 'phone' => $guarantor->guarantor_phone];
            }
        }

        if ($employee->position_id !== null && (int) $employee->position_id !== (int) $data['position_id'] && ! in_array('position', $confirmed, true)) {
            $conflicts['position'] = ['position_id' => $employee->position_id, 'name' => $employee->position?->name];
        }

        if ($conflicts !== []) {
            $this->fail(
                'Some details are already recorded for this employee and differ from what was entered. Nothing was changed: confirm the replacement of each one, or keep the existing value.',
                'OVERWRITE_NOT_CONFIRMED',
                ['conflicts' => $conflicts],
            );
        }
    }

    /** @param  array<string, mixed>  $data */
    private function saveEmergencyContact(Employee $employee, array $data): void
    {
        $employee->forceFill([
            'secondary_contact_name' => trim((string) $data['emergency_contact_name']),
            'secondary_contact_phone' => trim((string) $data['emergency_contact_phone']),
        ])->save();
    }

    /** @param  array<string, mixed>  $data */
    private function saveGuarantor(Employee $employee, array $data, Admin $actor): void
    {
        $payload = [
            'guarantor_name' => trim((string) $data['guarantor_name']),
            'guarantor_phone' => trim((string) $data['guarantor_phone']),
        ];

        if (! empty($data['guarantor_relationship'])) {
            $payload['relationship'] = trim((string) $data['guarantor_relationship']);
        }

        $existing = $employee->guarantor()->first();
        $unchanged = $existing !== null
            && $this->sameText($existing->guarantor_name, $payload['guarantor_name'])
            && EmployeeDuplicateFinder::phoneKey($existing->guarantor_phone) === EmployeeDuplicateFinder::phoneKey($payload['guarantor_phone'])
            && (! isset($payload['relationship']) || $this->sameText($existing->relationship, $payload['relationship']));

        if (! $unchanged) {
            $this->guarantors->handle($employee, $payload, $actor);
        }
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  list<string>  $confirmed
     */
    private function savePosition(Employee $employee, array $data, array $confirmed): void
    {
        $same = (int) $employee->position_id === (int) $data['position_id'];

        if ($employee->position_id === null || ($same === false && in_array('position', $confirmed, true))) {
            $employee->forceFill(['position_id' => (int) $data['position_id']])->save();
        }
    }

    private function filled(?string $value): bool
    {
        return trim((string) $value) !== '';
    }

    private function sameText(?string $a, ?string $b): bool
    {
        $normalise = fn (?string $v): string => mb_strtolower(preg_replace('/\s+/', ' ', trim((string) $v)) ?? '');

        return $normalise($a) === $normalise($b);
    }

    /**
     * @param  array<string, mixed>|null  $errors
     */
    private function fail(string $message, string $code, ?array $errors = null): never
    {
        throw new HttpResponseException(ApiResponse::error($message, $code, 422, $errors));
    }
}
