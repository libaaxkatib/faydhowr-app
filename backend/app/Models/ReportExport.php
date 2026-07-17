<?php

namespace App\Models;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use Database\Factories\ReportExportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable([
    'report_id',
    'report_type',
    'requested_by',
    'format',
    'filters',
    'status',
    'file_path',
    'started_at',
    'completed_at',
    'failed_at',
    'failure_reason',
])]
class ReportExport extends Model
{
    /** @use HasFactory<ReportExportFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Report, $this>
     */
    public function report(): BelongsTo
    {
        return $this->belongsTo(Report::class);
    }

    /**
     * @return BelongsTo<Admin, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'report_type' => ReportType::class,
            'format' => ReportExportFormat::class,
            'filters' => 'array',
            'status' => ReportExportStatus::class,
            'started_at' => 'datetime',
            'completed_at' => 'datetime',
            'failed_at' => 'datetime',
        ];
    }
}
