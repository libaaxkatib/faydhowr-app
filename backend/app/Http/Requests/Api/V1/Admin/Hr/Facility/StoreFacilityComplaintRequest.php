<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

use App\Enums\FacilityComplaintType;
use Illuminate\Validation\Rule;

class StoreFacilityComplaintRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return [
            'client_company_id' => ['required', 'integer', 'exists:client_companies,id'],
            'facility_inspection_id' => ['nullable', 'integer', 'exists:facility_inspections,id'],
            'type' => ['required', Rule::in(FacilityComplaintType::values())],
            'details' => ['required', 'string', 'min:5', 'max:5000'],
            'reporter_name' => ['nullable', 'string', 'max:150'],
            'reporter_type' => ['nullable', 'string', 'max:60'],
            'reporter_phone' => ['nullable', 'string', 'max:40'],
            'corrective_action' => ['nullable', 'string', 'max:2000'],
            'follow_up_on' => ['nullable', 'date', 'after_or_equal:reported_on'],
            'reporter_employee_id' => ['nullable', 'integer', 'exists:employees,id'],
            'responsible_person' => ['nullable', 'string', 'max:150'],
            'response' => ['nullable', 'string', 'max:5000'],
            'reported_on' => ['required', 'date', 'before_or_equal:today'],
            'responded_on' => ['nullable', 'date', 'after_or_equal:reported_on', 'before_or_equal:today'],
            // The status is never taken from the request: a complaint always starts open.
            'status' => ['prohibited'],
            'satisfaction' => ['prohibited'],
        ];
    }
}
