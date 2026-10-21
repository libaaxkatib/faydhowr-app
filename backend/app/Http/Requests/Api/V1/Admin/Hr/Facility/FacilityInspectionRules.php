<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

use App\Enums\FacilityItemKey;
use App\Enums\FacilityItemResult;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/** The rules of one inspection's checked items and uniform issues, shared by the create and the edit request. */
final class FacilityInspectionRules
{
    /** @return array<string, mixed> */
    public static function itemRules(bool $required): array
    {
        $presence = $required ? 'required' : 'sometimes';

        return [
            'items' => [$presence, 'array', 'size:'.count(FacilityItemKey::cases())],
            'items.*.item_key' => ['required', 'distinct', Rule::in(FacilityItemKey::values())],
            'items.*.applicable' => ['required', 'boolean'],
            // An item that applies has exactly one of the two outcomes; one that does not apply has none.
            'items.*.result' => ['nullable', Rule::in(FacilityItemResult::values()), 'required_if:items.*.applicable,true', 'prohibited_if:items.*.applicable,false'],
            // An unsatisfactory item must say what is wrong and what will be done about it.
            'items.*.issue_description' => ['nullable', 'string', 'max:2000', 'required_if:items.*.result,unsatisfactory'],
            'items.*.corrective_action' => ['nullable', 'string', 'max:2000', 'required_if:items.*.result,unsatisfactory'],
        ];
    }

    /** @return array<string, mixed> */
    public static function uniformRules(): array
    {
        return [
            'uniform_issues' => ['sometimes', 'array', 'max:200'],
            'uniform_issues.*.employee_id' => ['required', 'integer', 'exists:employees,id'],
            'uniform_issues.*.issue' => ['required', 'string', 'max:2000'],
            'uniform_issues.*.warning_given' => ['sometimes', 'boolean'],
            'uniform_issues.*.reason_not_worn' => ['nullable', 'string', 'max:2000'],
            'uniform_issues.*.corrective_action' => ['nullable', 'string', 'max:2000'],
            'uniform_issues.*.follow_up_on' => ['nullable', 'date'],
        ];
    }

    /** All twelve items must be present exactly once (an omitted item would silently look "not checked"). */
    public static function everyItemPresent(Validator $validator, ?array $items): void
    {
        if (! is_array($items)) {
            return;
        }

        $given = array_map(fn ($i): string => (string) ($i['item_key'] ?? ''), $items);
        $missing = array_diff(FacilityItemKey::values(), $given);

        if ($missing !== []) {
            $validator->errors()->add('items', 'Every item must be answered or marked not applicable. Missing: '.implode(', ', $missing).'.');
        }
    }
}
