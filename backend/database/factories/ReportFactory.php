<?php

namespace Database\Factories;

use App\Enums\ReportFormat;
use App\Enums\ReportType;
use App\Models\Admin;
use App\Models\Report;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Report>
 */
class ReportFactory extends Factory
{
    protected $model = Report::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'report_type' => ReportType::Bookings,
            'format' => ReportFormat::Json,
            'filters' => [
                'date_from' => now()->subMonth()->toDateString(),
                'date_to' => now()->toDateString(),
            ],
            'generated_by' => Admin::factory(),
            'generated_at' => now(),
            'created_at' => now(),
        ];
    }

    public function forAdmin(?Admin $admin = null): static
    {
        return $this->state(fn (): array => [
            'generated_by' => $admin?->id ?? Admin::factory(),
        ]);
    }

    public function type(ReportType $type): static
    {
        return $this->state(fn (): array => [
            'report_type' => $type,
        ]);
    }

    public function format(ReportFormat $format): static
    {
        return $this->state(fn (): array => [
            'format' => $format,
        ]);
    }
}
