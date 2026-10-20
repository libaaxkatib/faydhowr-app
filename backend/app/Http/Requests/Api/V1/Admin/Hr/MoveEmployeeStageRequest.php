<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Actions\Hr\MoveExistingEmployeeToStageAction;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class MoveEmployeeStageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', Rule::in(MoveExistingEmployeeToStageAction::targets())],
            // The length is enforced by the action (so the message and code are the same for every caller).
            'reason' => ['required', 'string', 'max:2000'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
