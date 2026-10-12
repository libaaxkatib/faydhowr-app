<?php

namespace App\Support\Hr;

use App\Models\Employee;
use Illuminate\Support\Collection;

/**
 * Finds existing employees (including archived ones) that a new registration
 * would duplicate. A phone is compared on its last nine digits so
 * "0615123456", "615123456" and "+252615123456" are the same number. An
 * identical full name (ignoring case and spacing) is a weaker signal and is
 * reported separately. Matches are advisory input for a human decision; the
 * registration flow requires an explicit, reasoned confirmation to proceed.
 */
class EmployeeDuplicateFinder
{
    public const MATCH_PHONE = 'phone';

    public const MATCH_NAME = 'name';

    public static function phoneKey(?string $phone): ?string
    {
        $digits = preg_replace('/\D/', '', (string) $phone) ?? '';

        if ($digits === '') {
            return null;
        }

        return strlen($digits) >= 9 ? substr($digits, -9) : $digits;
    }

    /**
     * SQL that strips the usual phone punctuation from a stored column, so "+252 615 123 456" and
     * "615-123-456" are found by their digits. Works on SQLite and PostgreSQL.
     */
    public static function digitsSql(string $column): string
    {
        $expression = $column;

        foreach ([' ', '-', '+', '.', '(', ')'] as $character) {
            $expression = "replace({$expression}, '{$character}', '')";
        }

        return $expression;
    }

    /**
     * @param  array{phone?: ?string, alternate_phone?: ?string, full_name?: ?string}  $candidate
     * @return Collection<int, array{id: int, employee_number: string, full_name: string, status: string, archived: bool, matched_on: list<string>}>
     */
    public function find(array $candidate, ?int $ignoreEmployeeId = null): Collection
    {
        $found = [];

        $keys = array_values(array_unique(array_filter([
            self::phoneKey($candidate['phone'] ?? null),
            self::phoneKey($candidate['alternate_phone'] ?? null),
        ])));

        foreach ($keys as $key) {
            // Never match on a short fragment: fewer than 9 digits is not a full number.
            if (strlen($key) < 9) {
                continue;
            }

            $employees = Employee::withTrashed()
                ->where(fn ($q) => $q->whereRaw(self::digitsSql('phone').' like ?', ['%'.$key])->orWhereRaw(self::digitsSql('alternate_phone').' like ?', ['%'.$key]))
                ->get();

            foreach ($employees as $employee) {
                if ($employee->id === $ignoreEmployeeId) {
                    continue;
                }

                $employeeKeys = [self::phoneKey($employee->phone), self::phoneKey($employee->alternate_phone)];

                if (in_array($key, $employeeKeys, true)) {
                    $found[$employee->id] = $this->describe($employee, $found[$employee->id]['matched_on'] ?? [], self::MATCH_PHONE);
                }
            }
        }

        $name = $this->normalizeName($candidate['full_name'] ?? null);

        if ($name !== null) {
            $employees = Employee::withTrashed()->whereRaw('lower(full_name) = ?', [$name])->get();

            foreach ($employees as $employee) {
                if ($employee->id === $ignoreEmployeeId) {
                    continue;
                }

                $found[$employee->id] = $this->describe($employee, $found[$employee->id]['matched_on'] ?? [], self::MATCH_NAME);
            }
        }

        return collect(array_values($found));
    }

    private function normalizeName(?string $name): ?string
    {
        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', (string) $name) ?? ''));

        return $name === '' ? null : $name;
    }

    /**
     * @param  list<string>  $matchedOn
     * @return array{id: int, employee_number: string, full_name: string, status: string, archived: bool, matched_on: list<string>}
     */
    private function describe(Employee $employee, array $matchedOn, string $reason): array
    {
        return [
            'id' => $employee->id,
            'employee_number' => $employee->employee_number,
            'full_name' => $employee->full_name,
            'status' => $employee->status->value,
            'archived' => $employee->trashed(),
            'matched_on' => array_values(array_unique([...$matchedOn, $reason])),
        ];
    }
}
