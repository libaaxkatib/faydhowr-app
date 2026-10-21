<?php

namespace App\Models;

use App\Enums\FacilityItemKey;
use App\Enums\FacilityItemResult;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['facility_inspection_id', 'item_key', 'applicable', 'result', 'issue_description', 'corrective_action'])]
class FacilityInspectionItem extends Model
{
    protected function casts(): array
    {
        return ['item_key' => FacilityItemKey::class, 'result' => FacilityItemResult::class, 'applicable' => 'boolean'];
    }

    public function inspection(): BelongsTo
    {
        return $this->belongsTo(FacilityInspection::class, 'facility_inspection_id');
    }
}
