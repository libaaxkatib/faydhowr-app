<?php

namespace App\Support\Customer;

use App\Models\CustomerProfile;
use Illuminate\Support\Facades\DB;

final class CustomerCodeGenerator
{
    /**
     * Generate the next immutable Customer Code in the form CUS-000001.
     */
    public function next(): string
    {
        return DB::transaction(function (): string {
            CustomerProfile::query()->withTrashed()->lockForUpdate()->orderByDesc('id')->limit(1)->get();

            $sequence = $this->nextSequence();

            return sprintf('CUS-%06d', $sequence);
        });
    }

    private function nextSequence(): int
    {
        $numbers = CustomerProfile::query()
            ->withTrashed()
            ->pluck('customer_number');

        $max = 0;

        foreach ($numbers as $number) {
            if (! is_string($number)) {
                continue;
            }

            if (preg_match('/^CUS-(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);

                continue;
            }

            if (preg_match('/(\d+)$/', $number, $matches) === 1) {
                $max = max($max, (int) $matches[1]);
            }
        }

        return $max + 1;
    }
}
