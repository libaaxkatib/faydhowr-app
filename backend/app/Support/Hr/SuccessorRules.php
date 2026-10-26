<?php

namespace App\Support\Hr;

use App\Enums\SalaryFrequency;
use App\Models\AssignmentSuccession;
use Illuminate\Validation\Rule;

/**
 * Request rules for the replacement choice (docs/HR_PAYROLL_RULES.md N1-N3), shared by every request that can end a Permanent assignment.
 * The business checks (does this Temporary really cover that position, is this person on the Waiting List) are made by PermanentSuccession.
 */
final class SuccessorRules
{
    /** @return array<string, array<int, mixed>> */
    public static function for(string $prefix): array
    {
        return [
            $prefix => ['sometimes', 'nullable', 'array'],
            "{$prefix}.type" => ['required_with:'.$prefix, Rule::in(AssignmentSuccession::types())],
            "{$prefix}.employee_id" => ['required_with:'.$prefix, 'integer', 'exists:employees,id'],
            // The date the replacement's Permanent assignment and salary take effect, INCLUSIVE. Optional: the day after the last paid day when omitted.
            "{$prefix}.effective_date" => ['sometimes', 'nullable', 'date'],
            "{$prefix}.salary_amount" => ['sometimes', 'nullable', 'numeric', 'gt:0', 'max:9999999999.99'],
            "{$prefix}.salary_currency" => ['sometimes', 'nullable', 'string', 'size:3', 'alpha'],
            "{$prefix}.salary_frequency" => ['sometimes', 'nullable', Rule::in(SalaryFrequency::values())],
        ];
    }
}
