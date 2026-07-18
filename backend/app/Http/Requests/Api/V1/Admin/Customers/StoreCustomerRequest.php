<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\CreateCustomerData;
use App\Enums\Customer\CustomerGender;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreCustomerRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:150'],
            'phone' => ['required', 'string', 'max:30', 'unique:users,phone'],
            'email' => ['nullable', 'email', 'max:191', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(8)],
            'gender' => ['nullable', Rule::in(CustomerGender::values())],
            'date_of_birth' => ['nullable', 'date', 'before:today'],
            'preferred_language' => ['nullable', Rule::in(['so', 'en', 'ar'])],
            'tags' => ['nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'avatar_url' => ['nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): CreateCustomerData
    {
        return new CreateCustomerData(
            fullName: (string) $this->validated('full_name'),
            phone: (string) $this->validated('phone'),
            password: (string) $this->validated('password'),
            email: $this->validated('email'),
            gender: $this->filled('gender') ? CustomerGender::from((string) $this->validated('gender')) : null,
            dateOfBirth: $this->validated('date_of_birth'),
            preferredLanguage: (string) ($this->validated('preferred_language') ?? 'so'),
            tags: $this->validated('tags'),
            avatarUrl: $this->validated('avatar_url'),
        );
    }
}
