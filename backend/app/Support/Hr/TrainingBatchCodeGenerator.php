<?php

namespace App\Support\Hr;

use App\Models\TrainingBatch;
use Illuminate\Support\Facades\DB;

final class TrainingBatchCodeGenerator
{
    /**
     * Generate the next immutable batch number in the form TRN-000001.
     * Mirrors App\Support\Hr\EmployeeCodeGenerator exactly.
     */
    public function next(): string
    {
        return DB::transaction(function (): string {
            TrainingBatch::query()->lockForUpdate()->orderByDesc('id')->limit(1)->get();

            $sequence = $this->nextSequence();

            return sprintf('TRN-%06d', $sequence);
        });
    }

    private function nextSequence(): int
    {
        $numbers = TrainingBatch::query()->pluck('batch_number');

        $max = 0;

        foreach ($numbers as $number) {
            if (! is_string($number)) {
                continue;
            }

            if (preg_match('/^TRN-(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max + 1;
    }
}
