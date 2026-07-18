<?php

namespace App\Http\Requests\Api\V1\Quotation;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AcceptQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Acceptance references the latest revision by `revision_id` or
     * `version_number` (API Design §9.5). The reference is enforced inside
     * the row-locked acceptance transaction for every quotation that has an
     * issued revision.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'revision_id' => ['nullable', 'integer', 'min:1'],
            'version_number' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function revisionId(): ?int
    {
        return $this->filled('revision_id') ? $this->integer('revision_id') : null;
    }

    public function versionNumber(): ?int
    {
        return $this->filled('version_number') ? $this->integer('version_number') : null;
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
