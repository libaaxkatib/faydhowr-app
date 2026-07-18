<?php

namespace App\Http\Requests\Api\V1\Quotation;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AttachQuotationUploadsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'upload_ids' => ['required', 'array', 'min:1', 'max:10'],
            'upload_ids.*' => ['uuid'],
        ];
    }

    /**
     * @return list<string>
     */
    public function uploadUuids(): array
    {
        return array_values($this->validated('upload_ids'));
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'The given data was invalid.',
            'VALIDATION_ERROR',
            422,
            $validator->errors(),
        ));
    }
}
