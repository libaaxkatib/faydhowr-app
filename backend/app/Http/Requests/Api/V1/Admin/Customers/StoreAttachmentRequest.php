<?php

namespace App\Http\Requests\Api\V1\Admin\Customers;

class StoreAttachmentRequest extends CustomersFormRequest
{
    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'file' => [
                'required',
                'file',
                'max:10240',
                'mimes:jpg,jpeg,png,gif,webp,pdf,doc,docx,xls,xlsx,txt',
            ],
        ];
    }
}
