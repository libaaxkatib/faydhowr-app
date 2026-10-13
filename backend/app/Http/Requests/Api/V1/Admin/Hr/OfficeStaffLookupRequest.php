<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

class OfficeStaffLookupRequest extends OfficeStaffRequest
{
    public function rules(): array
    {
        return [
            'search' => ['required', 'string', 'min:2', 'max:100'],
        ];
    }
}
