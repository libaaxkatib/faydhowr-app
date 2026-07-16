<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'label',
    'contact_name',
    'phone',
    'line1',
    'line2',
    'city',
    'state_region',
    'postal_code',
    'country_code',
    'latitude',
    'longitude',
    'is_default',
    'is_active',
])]
class CustomerAddress extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<CustomerProfile, $this>
     */
    public function customerProfile(): BelongsTo
    {
        return $this->belongsTo(CustomerProfile::class);
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'latitude' => 'decimal:7',
            'longitude' => 'decimal:7',
            'is_default' => 'boolean',
            'is_active' => 'boolean',
        ];
    }
}
