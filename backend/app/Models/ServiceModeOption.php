<?php

namespace App\Models;

use App\Enums\ServiceMode;
use App\Enums\ServiceSubtype;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'mode',
    'subtype',
    'is_active',
])]
class ServiceModeOption extends Model
{
    use HasFactory;

    /**
     * @return BelongsTo<Service, $this>
     */
    public function service(): BelongsTo
    {
        return $this->belongsTo(Service::class);
    }

    /**
     * @return array<string, class-string>
     */
    protected function casts(): array
    {
        return [
            'mode' => ServiceMode::class,
            'subtype' => ServiceSubtype::class,
            'is_active' => 'boolean',
        ];
    }
}
