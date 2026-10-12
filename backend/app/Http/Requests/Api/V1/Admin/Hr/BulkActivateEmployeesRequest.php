<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Actions\Hr\BulkActivateEmployeesAction;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class BulkActivateEmployeesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'records' => ['required', 'array', 'min:1', 'max:'.BulkActivateEmployeesAction::MAX_RECORDS],
            'records.*.employee_id' => ['required', 'integer', 'distinct'],
            'records.*.source_ref' => ['nullable', 'string', 'max:150'],
            'records.*.expected_employee_number' => ['nullable', 'string', 'max:20'],
            'records.*.expected_status' => ['nullable', 'string', 'max:20'],
            'note' => ['required', 'string', 'min:10', 'max:300'],
            'joining_date' => ['nullable', 'date'],
            // Super Admin only. Needed for employees whose move to Active is not a normal lifecycle step.
            'override_reason' => ['nullable', 'string', 'max:2000'],
            'dry_run' => ['sometimes', 'boolean'],
            'confirm' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * A real (non dry-run) run must be explicitly confirmed.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($this->has('dry_run') && ! $this->boolean('dry_run') && ! $this->boolean('confirm')) {
                $validator->errors()->add('confirm', 'A real run (dry_run=false) must be confirmed with confirm=true.');
            }
        });
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
