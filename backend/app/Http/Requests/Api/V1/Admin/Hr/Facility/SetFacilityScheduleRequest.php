<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

class SetFacilityScheduleRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return [
            'frequency_days' => ['required', 'integer', 'min:1', 'max:730'],
            'due_soon_days' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:60'],
            'notes' => ['sometimes', 'nullable', 'string', 'max:1000'],
        ];
    }
}
