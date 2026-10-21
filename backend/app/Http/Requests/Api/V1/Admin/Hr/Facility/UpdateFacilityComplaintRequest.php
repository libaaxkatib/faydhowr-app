<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

class UpdateFacilityComplaintRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return [
            'details' => ['sometimes', 'required', 'string', 'min:5', 'max:5000'],
            'reporter_name' => ['sometimes', 'nullable', 'string', 'max:150'],
            'reporter_type' => ['sometimes', 'nullable', 'string', 'max:60'],
            'reporter_phone' => ['sometimes', 'nullable', 'string', 'max:40'],
            'corrective_action' => ['sometimes', 'nullable', 'string', 'max:2000'],
            'follow_up_on' => ['sometimes', 'nullable', 'date'],
            'reporter_employee_id' => ['sometimes', 'nullable', 'integer', 'exists:employees,id'],
            'responsible_person' => ['sometimes', 'nullable', 'string', 'max:150'],
            'response' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'reported_on' => ['sometimes', 'required', 'date', 'before_or_equal:today'],
            'responded_on' => ['sometimes', 'nullable', 'date', 'before_or_equal:today'],
            // Status and satisfaction have their own explicit steps.
            'status' => ['prohibited'],
            'satisfaction' => ['prohibited'],
        ];
    }
}
