<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['client_company_id', 'uploaded_by', 'file_name', 'file_type', 'file_size', 'disk', 'file_path', 'sha256', 'notes'])]
class ClientCompanyAgreement extends Model
{
    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'uploaded_by');
    }
}
