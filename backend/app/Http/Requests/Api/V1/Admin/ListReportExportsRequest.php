<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Data\Reports\ReportCursorPagination;
use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class ListReportExportsRequest extends FormRequest
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
        return [
            'status' => ['sometimes', 'nullable', Rule::enum(ReportExportStatus::class)],
            'report_type' => ['sometimes', 'nullable', Rule::enum(ReportType::class)],
            'format' => ['sometimes', 'nullable', Rule::enum(ReportExportFormat::class)],
            'requested_by' => ['sometimes', 'nullable', 'integer', 'min:1'],
            'created_from' => ['sometimes', 'nullable', 'date'],
            'created_to' => ['sometimes', 'nullable', 'date'],
            'sort' => ['sometimes', 'nullable', Rule::in(['created_at'])],
            'direction' => ['sometimes', 'nullable', Rule::in(['asc', 'desc'])],
            'cursor' => ['sometimes', 'nullable', 'string'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.ReportCursorPagination::MAX_LIMIT],
        ];
    }

    /**
     * Validated history filters only; pagination and sorting are exposed
     * through their own helpers.
     *
     * @return array<string, mixed>
     */
    public function historyFilters(): array
    {
        return array_filter([
            'status' => $this->validated('status'),
            'report_type' => $this->validated('report_type'),
            'format' => $this->validated('format'),
            'requested_by' => $this->validated('requested_by'),
            'created_from' => $this->validated('created_from'),
            'created_to' => $this->validated('created_to'),
        ], fn (mixed $value): bool => $value !== null && $value !== '');
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

    public function sortDirection(): string
    {
        $direction = $this->validated('direction');

        return $direction === null || $direction === '' ? 'desc' : $direction;
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
