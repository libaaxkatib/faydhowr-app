<?php

namespace App\Models;

use App\Enums\Marketing\ResponsiblePartyType;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'marketing_record_id',
    'responsible_party_type',
    'company_name',
    'responsible_person_name',
    'phone',
    'location',
    'project_type',
    'project_size',
    'construction_completion_date',
    'fayadhowr_work_date',
])]
class ProjectDetail extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'responsible_party_type' => ResponsiblePartyType::class,
            'construction_completion_date' => 'date',
            'fayadhowr_work_date' => 'date',
        ];
    }

    public function marketingRecord(): BelongsTo
    {
        return $this->belongsTo(MarketingRecord::class);
    }
}
