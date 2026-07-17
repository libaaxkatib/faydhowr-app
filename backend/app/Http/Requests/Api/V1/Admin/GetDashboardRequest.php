<?php

namespace App\Http\Requests\Api\V1\Admin;

use App\Enums\DashboardDateFilter;
use App\Support\ApiResponse;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

class GetDashboardRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null;
    }

    /**
     * Start and end dates are only meaningful for the custom date range
     * filter; they are ignored for every predefined period.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'filter' => ['sometimes', 'nullable', Rule::enum(DashboardDateFilter::class)],
            'start_date' => [
                'nullable',
                'date',
                'required_if:filter,'.DashboardDateFilter::CustomDateRange->value,
            ],
            'end_date' => [
                'nullable',
                'date',
                'required_if:filter,'.DashboardDateFilter::CustomDateRange->value,
                'after_or_equal:start_date',
            ],
        ];
    }

    public function dateFilter(): ?DashboardDateFilter
    {
        $filter = $this->validated('filter');

        return $filter === null ? null : DashboardDateFilter::from($filter);
    }

    public function startDate(): ?CarbonImmutable
    {
        $startDate = $this->validated('start_date');

        return $startDate === null ? null : CarbonImmutable::parse($startDate);
    }

    public function endDate(): ?CarbonImmutable
    {
        $endDate = $this->validated('end_date');

        return $endDate === null ? null : CarbonImmutable::parse($endDate);
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
