<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportFormat;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class GenerateReportRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * The report type is determined by the dedicated endpoint's controller;
     * clients must never send it.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'report_type' => ['prohibited'],
            'format' => ['sometimes', 'nullable', Rule::enum(ReportFormat::class)],
            'cursor' => ['sometimes', 'nullable', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ReportCursorPagination::MAX_LIMIT],
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

    public function cursorPagination(): ReportCursorPagination
    {
        $limit = $this->validated('limit');
        $cursor = $this->validated('cursor');

        return new ReportCursorPagination(
            limit: $limit === null ? ReportCursorPagination::DEFAULT_LIMIT : (int) $limit,
            cursor: $cursor === null || $cursor === '' ? null : $cursor,
        );
    }

    public function reportFormat(): ReportFormat
    {
        $format = $this->validated('format');

        return $format === null ? ReportFormat::Json : ReportFormat::from($format);
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
