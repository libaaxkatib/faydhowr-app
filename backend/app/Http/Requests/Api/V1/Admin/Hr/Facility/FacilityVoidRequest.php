<?php

namespace App\Http\Requests\Api\V1\Admin\Hr\Facility;

/** Voiding an inspection or a complaint always needs a reason (it is kept in the history). */
class FacilityVoidRequest extends FacilityFormRequest
{
    public function rules(): array
    {
        return ['reason' => ['required', 'string', 'min:5', 'max:500']];
    }
}
