<?php

namespace App\Models;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use Database\Factories\ReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'report_type',
    'format',
    'filters',
    'generated_by',
    'generated_at',
])]
class Report extends Model
{
    /** @use HasFactory<ReportFactory> */
    use HasFactory;

    public const UPDATED_AT = null;

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function generatedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'generated_by');
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ReportFormat::class,
            'filters' => 'array',
            'generated_at' => 'datetime',
        ];
    }
}
