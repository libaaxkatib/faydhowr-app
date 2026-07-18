<?php

namespace App\Http\Requests\Api\V1\Admin\Quotations;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class AdminAcceptQuotationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Admin acceptance records the offline agreement context: the latest
     * revision reference and a mandatory reason (API Design §18.10).
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'revision_id' => ['required', 'integer', 'min:1'],
            'reason' => ['required', 'string', 'max:500'],
        ];
    }

    public function revisionId(): int
    {
        return $this->integer('revision_id');
    }

    public function reason(): string
    {
        return (string) $this->string('reason');
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
