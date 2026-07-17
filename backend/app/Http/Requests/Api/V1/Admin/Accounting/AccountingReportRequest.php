<?php

namespace App\Http\Requests\Api\V1\Admin\Accounting;

use App\Support\ApiResponse;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Carbon;

/**
 * Shared filter contract for the trial balance and financial statement
 * endpoints: either an accounting period id, or an optional date range.
 */
class AccountingReportRequest extends FormRequest
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
            'period_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:accounting_periods,id',
                'prohibits:date_from,date_to',
            ],
            'date_from' => ['sometimes', 'nullable', 'date'],
            'date_to' => ['sometimes', 'nullable', 'date', 'after_or_equal:date_from'],
        ];
    }

    public function periodId(): ?int
    {
        $periodId = $this->validated('period_id');

        return $periodId === null ? null : (int) $periodId;
    }

    public function startDate(): ?Carbon
    {
        $date = $this->validated('date_from');

        return $date === null ? null : Carbon::parse($date)->startOfDay();
    }

    public function endDate(): ?Carbon
    {
        $date = $this->validated('date_to');

        return $date === null ? null : Carbon::parse($date)->startOfDay();
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
