<?php

namespace App\Http\Requests\Api\V1\Admin\Hr;

use App\Enums\ImportType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Upload of a workbook to stage. Authorization is enforced by the route middleware (Super Admin and the
 * hr.import permission); the file's real type and content are verified again by the WorkbookReader.
 */
class StoreImportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::in(ImportType::values())],
            'file' => ['required', 'file', 'max:5120'],
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            ApiResponse::error('The given data was invalid.', 'VALIDATION_ERROR', 422, $validator->errors()),
        );
    }
}
