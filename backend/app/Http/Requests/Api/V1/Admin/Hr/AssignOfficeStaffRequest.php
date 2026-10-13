<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

/** An EXISTING employee added to Office Staff: no second employee record is ever created. */
class AssignOfficeStaffRequest extends OfficeStaffRequest
{
    public function rules(): array
    {
        return [
            ...$this->sharedRules(),
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
        ];
    }
}
