<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\ReportExportFormat;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class CreateReportExportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The report type comes from the report route binding; clients only
     * choose the export format and optional filters.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_type' => ['prohibited'],
            'format' => ['required', Rule::enum(ReportExportFormat::class)],
            'filters' => ['sometimes', 'array'],
            'filters.date_from' => ['sometimes', 'nullable', 'date'],
            'filters.date_to' => ['sometimes', 'nullable', 'date'],
            'filters.status' => ['sometimes', 'nullable'],
            'filters.customer_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.supplier_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.admin_id' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'filters.payment_status' => ['sometimes', 'nullable'],
        ];
    }

    /**
     * Raw filters to be normalized by NormalizeReportFiltersAction.
     *
     * @return array<string, mixed>
     */
    public function reportFilters(): array
    {
        /** @var array<string, mixed> */
        return $this->validated('filters', []);
    }

    public function exportFormat(): ReportExportFormat
    {
        return ReportExportFormat::from($this->validated('format'));
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
