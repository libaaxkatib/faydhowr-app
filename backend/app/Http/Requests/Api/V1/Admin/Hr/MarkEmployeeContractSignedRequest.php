<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * The signing method itself (scanned physical signature, digital signature,
 * or otherwise) is deliberately unspecified per docs/HRM_MARKETING_SRS.md HR
 * §28 - this only records the resulting signed date and, optionally, the
 * stored signed document.
 */
class MarkEmployeeContractSignedRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'signed_date' => ['required', 'date'],
            'signed_document_id' => ['nullable', 'integer', 'exists:employee_documents,id'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
