<?php

namespace App\Support\Reconciliation;

use App\Models\DataIssue;
use Illuminate\Support\Facades\DB;

final class DataIssueNumberGenerator
{
    /**
     * Generate the next immutable issue number in the form DI-000001.
     * Mirrors App\Support\Hr\EmployeeCodeGenerator exactly.
     */
    public function next(): string
    {
        return DB::transaction(function (): string {
            DataIssue::query()->lockForUpdate()->orderByDesc('id')->limit(1)->get();

            $sequence = $this->nextSequence();

            return sprintf('DI-%06d', $sequence);
        });
    }

    private function nextSequence(): int
    {
        $numbers = DataIssue::query()->pluck('issue_number');

        $max = 0;

        foreach ($numbers as $number) {
            if (! is_string($number)) {
                continue;
            }

            if (preg_match('/^DI-(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max + 1;
    }
}
