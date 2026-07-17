<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Support\ApiResponse;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

class DownloadReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Downloads take no input; the export is resolved from the route binding.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }

    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(ApiResponse::error(
            'Unauthenticated.',
            'UNAUTHENTICATED',
            401,
        ));
    }
}
