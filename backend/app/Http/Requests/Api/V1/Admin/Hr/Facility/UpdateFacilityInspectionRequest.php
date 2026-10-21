<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

use Illuminate\Contracts\Validation\Validator;

/** Editing an inspection: the company cannot be changed here. Anything not sent stays as it is. */
class UpdateFacilityInspectionRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return [
            'work_location_id' => ['sometimes', 'nullable', 'integer', 'exists:work_locations,id'],
            'inspector_name' => ['sometimes', 'required', 'string', 'max:150'],
            'inspected_on' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'general_notes' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'next_follow_up_on' => ['sometimes', 'nullable', 'date'],
            ...FacilityInspectionRules::itemRules(false),
            ...FacilityInspectionRules::uniformRules(),
            // The inspector's answer to "was there a staff / customer complaint?". Complaints themselves are edited on the Complaints screen.
            'staff_complaints_reported' => ['sometimes', 'boolean'],
            'customer_complaints_reported' => ['sometimes', 'boolean'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            FacilityInspectionRules::everyItemPresent($validator, $this->input('items'));

            $inspection = $this->route('facilityInspection');
            $on = $this->input('inspected_on', $inspection?->inspected_on?->toDateString());
            $followUp = $this->input('next_follow_up_on', $inspection?->next_follow_up_on?->toDateString());

            foreach (['staff', 'customer'] as $type) {
                $key = "{$type}_complaints_reported";
                if ($inspection === null || ! $this->has($key)) {
                    continue;
                }
                $linked = $inspection->complaints()->counted()->where('type', $type)->count();
                if ($this->boolean($key) && $linked === 0) {
                    $validator->errors()->add($key, "Register the {$type} complaint first (on the Complaints screen, linked to this inspection), or leave it at \"No {$type} complaint\".");
                }
                if (! $this->boolean($key) && $linked > 0) {
                    $validator->errors()->add($key, "This inspection has {$linked} {$type} complaint(s) on record, so it cannot be changed to \"No {$type} complaint\". Void the complaint first if it was registered by mistake.");
                }
            }
            if ($on !== null && $followUp !== null && $followUp < $on) {
                $validator->errors()->add('next_follow_up_on', 'The next follow-up cannot be before the inspection date.');
            }
        }];
    }
}
