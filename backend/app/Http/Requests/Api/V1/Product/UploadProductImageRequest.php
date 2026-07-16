<?php

namespace App\Http\Requests\Api\V1\Product;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rules\File;

class UploadProductImageRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var list<string> $allowedMimes */
        $allowedMimes = config('products.images.allowed_mimes', ['jpg', 'jpeg', 'png', 'webp']);
        $maxKilobytes = (int) config('products.images.max_kilobytes', 5120);

        return [
            'image' => [
                'required',
                File::image()
                    ->types($allowedMimes)
                    ->max($maxKilobytes),
            ],
        ];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'Unauthenticated.',
            'UNAUTHENTICATED',
            401,
        ));
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
