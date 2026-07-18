<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\CreateNoteData;

class StoreNoteRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'note' => ['required', 'string', 'max:5000'],
        ];
    }

    public function toData(): CreateNoteData
    {
        return new CreateNoteData((string) $this->validated('note'));
    }
}
