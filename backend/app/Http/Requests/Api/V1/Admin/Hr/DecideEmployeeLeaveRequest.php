<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DecideEmployeeLeaveRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        $rejecting = $this->routeIs('api.v1.admin.hr.employees.leaves.reject');

        return [
            'note' => $rejecting ? ['required', 'string', 'min:5', 'max:500'] : ['nullable', 'string', 'max:500'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
