<?php

namespace Database\Factories;

use App\Enums\ReportExportFormat;
use App\Enums\ReportExportStatus;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Report;
use App\Models\ReportExport;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ReportExport>
 */
class ReportExportFactory extends Factory
{
    protected $model = ReportExport::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_id' => Report::factory()->type(ReportType::Suppliers),
            'report_type' => ReportType::Suppliers,
            'requested_by' => Admin::factory(),
            'format' => ReportExportFormat::Pdf,
            'filters' => [],
            'status' => ReportExportStatus::Pending,
        ];
    }

    public function type(ReportType $type): static
    {
        return $this->state(fn (): array => [
            'report_type' => $type,
            'report_id' => Report::factory()->type($type),
        ]);
    }

    public function format(ReportExportFormat $format): static
    {
        return $this->state(fn (): array => [
            'format' => $format,
        ]);
    }

    public function processing(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportExportStatus::Processing,
            'started_at' => now(),
        ]);
    }

    public function completed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportExportStatus::Completed,
            'started_at' => now()->subMinute(),
            'completed_at' => now(),
            'file_path' => 'reports/exports/1/suppliers-placeholder.pdf',
        ]);
    }

    public function failed(): static
    {
        return $this->state(fn (): array => [
            'status' => ReportExportStatus::Failed,
            'started_at' => now()->subMinute(),
            'failed_at' => now(),
            'failure_reason' => 'Simulated export failure.',
        ]);
    }
}
