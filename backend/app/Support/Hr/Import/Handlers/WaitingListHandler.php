<?php

namespace App\Support\Hr\Import\Handlers;

use App\Actions\Hr\MoveEmployeeToWaitingAction;
use App\Enums\EmployeeStatus;
use App\Enums\ImportType;
use App\Models\Admin;
use App\Models\Employee;
use App\Models\ImportBatch;
use App\Models\ImportRow;
use App\Support\Hr\EmployeeDuplicateFinder;
use App\Support\Hr\EmployeeTransitionGuard;
use App\Support\Hr\Import\ImportNormalizer;
use App\Support\Hr\Import\ImportTypeHandler;
use App\Support\Hr\Import\RowOutcome;
use App\Support\Hr\Import\StagedRow;

/**
 * Waiting-list import. File columns: full_name, phone (the REGISTRATION phone), optional notes.
 *
 * This workflow only moves an EXISTING employee to Waiting. It never creates an employee, never changes
 * any employee detail, and never picks a "similar" person. A row is accepted only on a CONFIDENT match:
 * exactly one non-archived employee whose registration phone equals the row's phone (last nine digits)
 * AND whose name is equal after normalisation (case, spacing and punctuation ignored, word order kept).
 * Everything else - no match, a phone match with another name, several phone matches, an archived
 * employee, a match only on the alternate phone, a name match with another phone - goes to MANUAL REVIEW:
 * it is reported, never imported automatically and never rejected.
 *
 * The employee must be able to move to Waiting under the transition guard (applicant, recruitment or
 * practical). Already Waiting is skipped; Active or Inactive employees cannot be moved by this import.
 */
final class WaitingListHandler implements ImportTypeHandler
{
    public function __construct(
        private readonly MoveEmployeeToWaitingAction $moveToWaiting,
        private readonly EmployeeTransitionGuard $guard,
    ) {}

    public function type(): ImportType
    {
        return ImportType::WaitingList;
    }

    public function columns(): array
    {
        return [
            'full_name' => ['full name', 'name', 'employee name'],
            'phone' => ['registration phone', 'phone number', 'mobile', 'telephone'],
            'notes' => ['note', 'remarks'],
        ];
    }

    public function requiredColumns(): array
    {
        return ['full_name', 'phone'];
    }

    public function ignoredColumns(): array
    {
        return ['payment account', 'payment account number', 'payment number', 'account number', 'account'];
    }

    public function stage(array $cells, array &$seen): StagedRow
    {
        $name = trim((string) preg_replace('/\s+/u', ' ', $cells['full_name'] ?? ''));
        $phone = trim($cells['phone'] ?? '');
        $nameKey = ImportNormalizer::nameKey($name);
        $phoneKey = ImportNormalizer::phoneKey($phone);

        if ($nameKey === '') {
            return StagedRow::invalid('NAME_REQUIRED', 'The full name is required.');
        }

        if (! ImportNormalizer::isFullPhoneKey($phoneKey)) {
            return StagedRow::invalid('PHONE_INVALID', 'The registration phone must be a full number (at least 9 digits).', null, $nameKey, $phoneKey);
        }

        $payload = ['full_name' => $name, 'phone' => $phone, 'notes' => trim($cells['notes'] ?? '')];

        if (isset($seen['phones'][$phoneKey])) {
            return StagedRow::valid($payload, $nameKey, $phoneKey)->asRepeatInFile('phone number');
        }

        $seen['phones'][$phoneKey] = true;

        $match = $this->match($nameKey, $phoneKey, $name);

        if ($match['kind'] === 'review') {
            return StagedRow::review($match['code'], $match['message'], $payload, $nameKey, $phoneKey, ['employee_numbers' => $match['numbers']]);
        }

        /** @var Employee $employee */
        $employee = $match['employee'];
        $meta = ['employee_numbers' => [$employee->employee_number], 'current_status' => $employee->status->value];

        if ($employee->status === EmployeeStatus::Waiting) {
            return StagedRow::duplicate('ALREADY_WAITING', 'The employee is already on the waiting list. Nothing was changed.', $payload, $nameKey, $phoneKey, $meta, Employee::class, $employee->id);
        }

        if ($this->guard->evaluate($employee->status, EmployeeStatus::Waiting, EmployeeTransitionGuard::CONTEXT_WAITING_IMPORT) !== EmployeeTransitionGuard::DECISION_NORMAL) {
            return StagedRow::invalid('TRANSITION_NOT_ALLOWED', "The matched employee is {$employee->status->label()}; an {$employee->status->label()} employee cannot be moved to Waiting by an import.", $payload, $nameKey, $phoneKey, $meta);
        }

        return StagedRow::valid($payload, $nameKey, $phoneKey, $meta, Employee::class, $employee->id, 'Confident match; will be moved to Waiting.');
    }

    public function process(ImportRow $row, ImportBatch $batch, Admin $actor): RowOutcome
    {
        $employee = $row->entity_id === null ? null : Employee::query()->find($row->entity_id);

        if ($employee === null) {
            return RowOutcome::skipped('MATCH_LOST', 'The matched employee no longer exists or is archived. Nothing was changed.');
        }

        // The match must still be exactly what was previewed: same registration phone and same name.
        $payload = (array) $row->payload;

        if (ImportNormalizer::phoneKey($employee->phone) !== $row->phone_key || ImportNormalizer::nameKey($employee->full_name) !== $row->name_key) {
            return RowOutcome::skipped('MATCH_CHANGED', 'The employee record changed after the preview, so it no longer matches. Nothing was changed.', Employee::class, $employee->id);
        }

        if ($employee->status === EmployeeStatus::Waiting) {
            return RowOutcome::skipped('ALREADY_WAITING', 'The employee is already on the waiting list. Nothing was changed.', Employee::class, $employee->id);
        }

        if ($this->guard->evaluate($employee->status, EmployeeStatus::Waiting, EmployeeTransitionGuard::CONTEXT_WAITING_IMPORT) !== EmployeeTransitionGuard::DECISION_NORMAL) {
            return RowOutcome::skipped('TRANSITION_NOT_ALLOWED', 'The employee status changed after the preview and can no longer be moved to Waiting by an import.', Employee::class, $employee->id);
        }

        $note = "Waiting-list import #{$batch->id}, row {$row->row_number}.";

        if (($payload['notes'] ?? '') !== '') {
            $note .= ' '.mb_substr((string) $payload['notes'], 0, 200);
        }

        $this->moveToWaiting->handle($employee, $note, $actor, $batch->id);

        return RowOutcome::imported(Employee::class, $employee->id, 'Moved to Waiting.');
    }

    /**
     * @return array{kind: 'confident', employee: Employee}|array{kind: 'review', code: string, message: string, numbers: list<string>}
     */
    private function match(string $nameKey, string $phoneKey, string $name): array
    {
        $registration = [];
        $alternateOnly = [];
        $archived = [];

        foreach (Employee::withTrashed()->where(fn ($q) => $q->whereRaw(EmployeeDuplicateFinder::digitsSql('phone').' like ?', ['%'.$phoneKey])->orWhereRaw(EmployeeDuplicateFinder::digitsSql('alternate_phone').' like ?', ['%'.$phoneKey]))->get() as $candidate) {
            $isRegistration = ImportNormalizer::phoneKey($candidate->phone) === $phoneKey;
            $isAlternate = ImportNormalizer::phoneKey($candidate->alternate_phone) === $phoneKey;

            if ($candidate->trashed() && ($isRegistration || $isAlternate)) {
                $archived[] = $candidate;
            } elseif ($isRegistration) {
                $registration[] = $candidate;
            } elseif ($isAlternate) {
                $alternateOnly[] = $candidate;
            }
        }

        $numbers = fn (array $employees): array => array_map(fn (Employee $e): string => $e->employee_number, $employees);

        if (count($registration) > 1) {
            return ['kind' => 'review', 'code' => 'MULTIPLE_PHONE_MATCHES', 'message' => 'More than one employee has this registration phone. Decide which one it is.', 'numbers' => $numbers($registration)];
        }

        if (count($registration) === 1) {
            $employee = $registration[0];

            if (ImportNormalizer::nameKey($employee->full_name) !== $nameKey) {
                return ['kind' => 'review', 'code' => 'NAME_CONFLICT', 'message' => 'An employee has this registration phone but a different name. Check whether they are the same person.', 'numbers' => [$employee->employee_number]];
            }

            return ['kind' => 'confident', 'employee' => $employee];
        }

        if ($archived !== []) {
            return ['kind' => 'review', 'code' => 'ARCHIVED_MATCH', 'message' => 'The only match is an archived employee record. Review it manually.', 'numbers' => $numbers($archived)];
        }

        if ($alternateOnly !== []) {
            return ['kind' => 'review', 'code' => 'ALTERNATE_PHONE_ONLY_MATCH', 'message' => 'This number is only an employee\'s alternate phone, not their registration phone. Review it manually.', 'numbers' => $numbers($alternateOnly)];
        }

        $sameName = Employee::query()->whereRaw('lower(full_name) = ?', [mb_strtolower($name)])->get()->all();

        if ($sameName !== []) {
            return ['kind' => 'review', 'code' => 'NAME_ONLY_MATCH', 'message' => 'An employee has this name but a different registration phone. Check whether they are the same person.', 'numbers' => $numbers($sameName)];
        }

        return ['kind' => 'review', 'code' => 'NO_MATCH', 'message' => 'No employee matches this name and registration phone. It was not created and not rejected; review it manually.', 'numbers' => []];
    }
}
