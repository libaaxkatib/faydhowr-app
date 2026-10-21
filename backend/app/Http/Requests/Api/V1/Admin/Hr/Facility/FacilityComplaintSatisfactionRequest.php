<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

use App\Enums\FacilitySatisfaction;
use Illuminate\Validation\Rule;

/** `satisfaction` null (or "none") clears a wrongly recorded entry; the evidence/note is always required. */
class FacilityComplaintSatisfactionRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return [
            'satisfaction' => ['present', 'nullable', Rule::in(FacilitySatisfaction::values())],
            'evidence' => ['required', 'string', 'min:10', 'max:2000'],
            'recorded_on' => ['nullable', 'date', 'before_or_equal:today'],
        ];
    }
}
