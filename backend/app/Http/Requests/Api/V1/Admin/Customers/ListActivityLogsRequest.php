<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\Enums\Customer\ActivityType;
use Illuminate\Validation\Rule;

class ListActivityLogsRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'event_type' => ['sometimes', 'nullable', Rule::in(ActivityType::values())],
            'from' => ['sometimes', 'nullable', 'date'],
            'to' => ['sometimes', 'nullable', 'date'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}
