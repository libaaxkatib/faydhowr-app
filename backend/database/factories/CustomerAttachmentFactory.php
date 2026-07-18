<?php

namespace Database\Factories;

use App\Enums\Customer\AttachmentType;
use App\Models\Admin;
use App\Models\CustomerAttachment;
use App\Models\CustomerProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<CustomerAttachment>
 */
class CustomerAttachmentFactory extends Factory
{
    protected $model = CustomerAttachment::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_profile_id' => CustomerProfile::factory(),
            'admin_id' => Admin::factory(),
            'file_name' => 'document.pdf',
            'file_type' => AttachmentType::Pdf,
            'file_size' => 1024,
            'file_path' => 'customers/1/attachments/document.pdf',
        ];
    }
}
