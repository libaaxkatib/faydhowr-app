<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

use App\DataTransferObjects\Customer\UpdateCustomerData;
use App\Enums\Customer\CustomerGender;
use App\Models\CustomerProfile;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        /** @var CustomerProfile $customer */
        $customer = $this->route('customer');
        $userId = $customer->user_id;

        return [
            'full_name' => ['sometimes', 'string', 'max:150'],
            'phone' => ['sometimes', 'string', 'max:30', Rule::unique('users', 'phone')->ignore($userId)],
            'email' => ['sometimes', 'nullable', 'email', 'max:191', Rule::unique('users', 'email')->ignore($userId)],
            'gender' => ['sometimes', 'nullable', Rule::in(CustomerGender::values())],
            'date_of_birth' => ['sometimes', 'nullable', 'date', 'before:today'],
            'preferred_language' => ['sometimes', Rule::in(['so', 'en', 'ar'])],
            'tags' => ['sometimes', 'nullable', 'array'],
            'tags.*' => ['string', 'max:50'],
            'avatar_url' => ['sometimes', 'nullable', 'string', 'max:500'],
        ];
    }

    public function toData(): UpdateCustomerData
    {
        $validated = $this->validated();

        return new UpdateCustomerData(
            fullName: $validated['full_name'] ?? null,
            phone: $validated['phone'] ?? null,
            email: array_key_exists('email', $validated) ? $validated['email'] : null,
            gender: isset($validated['gender']) && $validated['gender'] !== null
                ? CustomerGender::from($validated['gender'])
                : null,
            dateOfBirth: array_key_exists('date_of_birth', $validated) ? $validated['date_of_birth'] : null,
            preferredLanguage: $validated['preferred_language'] ?? null,
            tags: array_key_exists('tags', $validated) ? $validated['tags'] : null,
            avatarUrl: array_key_exists('avatar_url', $validated) ? $validated['avatar_url'] : null,
            clearEmail: array_key_exists('email', $validated) && $validated['email'] === null,
            clearGender: array_key_exists('gender', $validated) && $validated['gender'] === null,
            clearDateOfBirth: array_key_exists('date_of_birth', $validated) && $validated['date_of_birth'] === null,
            clearTags: array_key_exists('tags', $validated) && $validated['tags'] === null,
            clearAvatarUrl: array_key_exists('avatar_url', $validated) && $validated['avatar_url'] === null,
        );
    }
}
