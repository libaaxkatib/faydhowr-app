<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * docs/HRM_MARKETING_SRS.md HR §10-11: exactly three canonical decision
 * outcomes going forward (approved/rejected/ku_celis_practical) - the
 * legacy pass/fail/pending values remain valid in the database's CHECK
 * constraint for old data but are no longer accepted from new submissions.
 */
class StorePracticalAssessmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'assessment_date' => ['required', 'date'],
            'result' => ['required', Rule::in(['approved', 'rejected', 'ku_celis_practical'])],
            'practical_batch_id' => ['nullable', 'integer', 'exists:practical_batches,id'],
            'notes' => ['nullable', 'string'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
