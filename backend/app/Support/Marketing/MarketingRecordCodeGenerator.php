<?php

namespace App\Support\Marketing;

use App\Models\MarketingRecord;
use Illuminate\Support\Facades\DB;

final class MarketingRecordCodeGenerator
{
    /**
     * Generate the next immutable record number in the form MKT-000001.
     * Mirrors App\Support\Customer\CustomerCodeGenerator / App\Support\Hr\EmployeeCodeGenerator.
     */
    public function next(): string
    {
        return DB::transaction(function (): string {
            MarketingRecord::query()->withTrashed()->lockForUpdate()->orderByDesc('id')->limit(1)->get();

            $sequence = $this->nextSequence();

            return sprintf('MKT-%06d', $sequence);
        });
    }

    private function nextSequence(): int
    {
        $numbers = MarketingRecord::query()->withTrashed()->pluck('record_number');

        $max = 0;

        foreach ($numbers as $number) {
            if (! is_string($number)) {
                continue;
            }

            if (preg_match('/^MKT-(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max + 1;
    }
}
