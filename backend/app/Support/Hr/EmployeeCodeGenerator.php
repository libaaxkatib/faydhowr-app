<?php

namespace App\Support\Hr;

use App\Models\Employee;
use Illuminate\Support\Facades\DB;

final class EmployeeCodeGenerator
{
    /**
     * Generate the next immutable Employee Number in the form EMP-000001.
     * Mirrors App\Support\Customer\CustomerCodeGenerator exactly.
     */
    public function next(): string
    {
        return DB::transaction(function (): string {
            Employee::query()->withTrashed()->lockForUpdate()->orderByDesc('id')->limit(1)->get();

            $sequence = $this->nextSequence();

            return sprintf('EMP-%06d', $sequence);
        });
    }

    private function nextSequence(): int
    {
        $numbers = Employee::query()->withTrashed()->pluck('employee_number');

        $max = 0;

        foreach ($numbers as $number) {
            if (! is_string($number)) {
                continue;
            }

            if (preg_match('/^EMP-(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max + 1;
    }
}
