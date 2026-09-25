<?php

namespace App\Support\Hr;

use App\Models\PracticalBatch;
use Illuminate\Support\Facades\DB;

final class PracticalBatchCodeGenerator
{
    /**
     * Generate the next immutable batch number in the form PRC-000001.
     * Mirrors App\Support\Hr\EmployeeCodeGenerator exactly.
     */
    public function next(): string
    {
        return DB::transaction(function (): string {
            PracticalBatch::query()->lockForUpdate()->orderByDesc('id')->limit(1)->get();

            $sequence = $this->nextSequence();

            return sprintf('PRC-%06d', $sequence);
        });
    }

    private function nextSequence(): int
    {
        $numbers = PracticalBatch::query()->pluck('batch_number');

        $max = 0;

        foreach ($numbers as $number) {
            if (! is_string($number)) {
                continue;
            }

            if (preg_match('/^PRC-(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max + 1;
    }
}
