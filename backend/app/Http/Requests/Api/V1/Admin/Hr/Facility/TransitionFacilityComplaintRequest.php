<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

use App\Enums\FacilityComplaintStatus;
use Illuminate\Validation\Rule;

class TransitionFacilityComplaintRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return [
            'status' => ['required', Rule::in(FacilityComplaintStatus::values())],
            'note' => ['nullable', 'string', 'max:2000'],
            'resolved_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
