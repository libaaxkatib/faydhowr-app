<?php

namespace Tests\Feature\Report;

use App\Actions\Report\GenerateReportAction;
use App\Actions\Report\NormalizeReportFiltersAction;
use App\Contracts\Reports\Generators\ReportGeneratorInterface;
use App\Data\Reports\NormalizedReportFilters;
use App\Enums\ReportType;
use App\Exceptions\Reports\InvalidReportFilterException;
use App\Http\Requests\Api\V1\Admin\GenerateReportRequest;
use App\Models\Admin;
use App\Services\Reports\ReportManager;
use Carbon\CarbonImmutable;
use Error;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Validator;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class ReportFilterTest extends TestCase
{
    use RefreshDatabase;

    private function normalize(array $filters): NormalizedReportFilters
    {
        return $this->app->make(NormalizeReportFiltersAction::class)->handle($filters);
    }

    public function test_dates_are_normalized_to_carbon_instances(): void
    {
        $filters = $this->normalize([
            'date_from' => '2026-01-01',
            'date_to' => '2026-07-16 23:59:59',
        ]);

        $this->assertInstanceOf(CarbonImmutable::class, $filters->dateFrom());
        $this->assertInstanceOf(CarbonImmutable::class, $filters->dateTo());
        $this->assertSame('2026-01-01', $filters->dateFrom()->toDateString());
        $this->assertSame('2026-07-16 23:59:59', $filters->dateTo()->format('Y-m-d H:i:s'));
    }

    public function test_invalid_date_is_rejected(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        $this->expectExceptionMessage('Report filter [date_from] must be a valid date');

        $this->normalize(['date_from' => 'not-a-date']);
    }

    public function test_non_string_date_is_rejected(): void
    {
        $this->expectException(InvalidReportFilterException::class);

        $this->normalize(['date_to' => ['2026-01-01']]);
    }

    public function test_date_from_after_date_to_is_rejected(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        $this->expectExceptionMessage('Report filter [date_from] must be before or equal to [date_to].');

        $this->normalize([
            'date_from' => '2026-07-16',
            'date_to' => '2026-01-01',
        ]);
    }

    public function test_ids_are_normalized_to_integers(): void
    {
        $filters = $this->normalize([
            'customer_id' => '5',
            'supplier_id' => 7,
            'admin_id' => ' 12 ',
        ]);

        $this->assertSame(5, $filters->customerId());
        $this->assertSame(7, $filters->supplierId());
        $this->assertSame(12, $filters->adminId());
    }

    public function test_non_integer_ids_are_rejected(): void
    {
        foreach (['abc', 1.5, '1.5', 0, -3, true] as $invalid) {
            try {
                $this->normalize(['customer_id' => $invalid]);
                $this->fail('Expected InvalidReportFilterException for value: '.var_export($invalid, true));
            } catch (InvalidReportFilterException $exception) {
                $this->assertStringContainsString('customer_id', $exception->getMessage());
            }
        }
    }

    public function test_empty_strings_are_normalized_to_null(): void
    {
        $filters = $this->normalize([
            'date_from' => '',
            'date_to' => '  ',
            'status' => '',
            'customer_id' => '',
            'payment_status' => '   ',
        ]);

        $this->assertNull($filters->dateFrom());
        $this->assertNull($filters->dateTo());
        $this->assertNull($filters->status());
        $this->assertNull($filters->customerId());
        $this->assertNull($filters->paymentStatus());
        $this->assertSame([], $filters->toArray());
    }

    public function test_status_is_trimmed_and_lowercased(): void
    {
        $filters = $this->normalize([
            'status' => '  Confirmed  ',
            'payment_status' => ' PAID ',
        ]);

        $this->assertSame('confirmed', $filters->status());
        $this->assertSame('paid', $filters->paymentStatus());
    }

    public function test_status_arrays_are_cleaned_of_empty_values(): void
    {
        $filters = $this->normalize([
            'status' => ['  Pending ', '', null, 'CONFIRMED', '   '],
        ]);

        $this->assertSame(['pending', 'confirmed'], $filters->status());
    }

    public function test_status_array_with_only_empty_values_becomes_null(): void
    {
        $filters = $this->normalize(['status' => ['', null, '   ']]);

        $this->assertNull($filters->status());
    }

    public function test_unsupported_filter_key_is_rejected(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        $this->expectExceptionMessage('Report filter [warehouse] is not supported.');

        $this->normalize(['warehouse' => 'main']);
    }

    public function test_invalid_filter_structure_is_rejected(): void
    {
        $this->expectException(InvalidReportFilterException::class);
        $this->expectExceptionMessage('Report filter [status] has an invalid structure.');

        $this->normalize(['status' => 123]);
    }

    public function test_report_type_filter_key_is_accepted_but_not_carried_into_value_object(): void
    {
        $filters = $this->normalize([
            'report_type' => 'bookings',
            'status' => 'open',
        ]);

        $this->assertSame(['status' => 'open'], $filters->toArray());
    }

    public function test_value_object_is_immutable(): void
    {
        $filters = new NormalizedReportFilters(customerId: 5);

        $reflection = new ReflectionClass(NormalizedReportFilters::class);

        $this->assertTrue($reflection->isFinal());

        foreach ($reflection->getProperties() as $property) {
            $this->assertTrue($property->isPrivate(), "Property [{$property->getName()}] must be private.");
            $this->assertTrue($property->isReadOnly(), "Property [{$property->getName()}] must be readonly.");
        }

        $this->expectException(Error::class);

        $filters->customerId = 99;
    }

    public function test_generators_accept_only_normalized_filters(): void
    {
        $interfaceParameter = (new ReflectionClass(ReportGeneratorInterface::class))
            ->getMethod('generate')
            ->getParameters()[0]
            ->getType();

        $this->assertInstanceOf(ReflectionNamedType::class, $interfaceParameter);
        $this->assertSame(NormalizedReportFilters::class, $interfaceParameter->getName());

        foreach ($this->app->make(ReportManager::class)->generators() as $generator) {
            $parameterType = (new ReflectionClass($generator))
                ->getMethod('generate')
                ->getParameters()[0]
                ->getType();

            $this->assertInstanceOf(ReflectionNamedType::class, $parameterType);
            $this->assertSame(
                NormalizedReportFilters::class,
                $parameterType->getName(),
                $generator::class.' must accept NormalizedReportFilters only.',
            );
        }
    }

    public function test_generator_echoes_normalized_applied_filters(): void
    {
        $filters = $this->normalize([
            'date_from' => '2026-01-01',
            'status' => ' Confirmed ',
            'customer_id' => '9',
        ]);

        $payload = $this->app->make(ReportManager::class)
            ->generatorFor(ReportType::Bookings)
            ->generate($filters);

        $this->assertSame($filters->toArray(), $payload['applied_filters']);
        $this->assertSame('confirmed', $payload['applied_filters']['status']);
        $this->assertSame(9, $payload['applied_filters']['customer_id']);
    }

    public function test_generate_report_action_persists_normalized_filters(): void
    {
        $admin = Admin::factory()->create();
        $filters = $this->normalize([
            'status' => ' Confirmed ',
            'supplier_id' => '3',
        ]);

        $result = $this->app->make(GenerateReportAction::class)
            ->handle($admin, ReportType::Suppliers, $filters);

        $this->assertSame(
            ['status' => 'confirmed', 'supplier_id' => 3],
            $result['report']->fresh()->filters,
        );
    }

    public function test_generate_report_request_validates_filter_shape(): void
    {
        $rules = (new GenerateReportRequest)->rules();

        $valid = Validator::make([
            'filters' => [
                'date_from' => '2026-01-01',
                'customer_id' => 5,
                'status' => 'confirmed',
            ],
        ], $rules);

        $this->assertFalse($valid->fails());

        $invalid = Validator::make([
            'report_type' => 'bookings',
            'filters' => [
                'date_from' => 'not-a-date',
                'customer_id' => 'abc',
            ],
        ], $rules);

        $this->assertTrue($invalid->fails());
        $this->assertArrayHasKey('report_type', $invalid->errors()->toArray());
        $this->assertArrayHasKey('filters.date_from', $invalid->errors()->toArray());
        $this->assertArrayHasKey('filters.customer_id', $invalid->errors()->toArray());
    }
}
