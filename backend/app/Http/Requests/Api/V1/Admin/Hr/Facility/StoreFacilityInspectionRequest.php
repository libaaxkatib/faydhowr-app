<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

use Illuminate\Contracts\Validation\Validator;

class StoreFacilityInspectionRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return [
            'client_company_id' => ['required', 'integer', 'exists:client_companies,id'],
            'work_location_id' => ['nullable', 'integer', 'exists:work_locations,id'],
            'inspector_name' => ['required', 'string', 'max:150'],
            'inspected_on' => ['required', 'date', 'before_or_equal:today'],
            'general_notes' => ['nullable', 'string', 'max:5000'],
            'next_follow_up_on' => ['nullable', 'date', 'after_or_equal:inspected_on'],
            ...FacilityInspectionRules::itemRules(true),
            ...FacilityInspectionRules::uniformRules(),
            ...FacilityComplaintRules::rules(),
        ];
    }

    public function after(): array
    {
        return [fn (Validator $validator) => FacilityInspectionRules::everyItemPresent($validator, $this->input('items')),
            fn (Validator $validator) => FacilityComplaintRules::check($validator, $this->input('complaints'), $this->input('staff_complaints_reported'), $this->input('customer_complaints_reported'), $this->input('inspected_on'))];
    }
}
