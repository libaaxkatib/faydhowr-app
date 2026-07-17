<?php

namespace Tests\Feature\Report;

use App\Actions\Report\GenerateReportAction;
use App\Contracts\Reports\ReportManagerInterface;
use App\Contracts\Reports\Services\BookingReportServiceInterface;
use App\Contracts\Reports\Services\CustomerReportServiceInterface;
use App\Contracts\Reports\Services\InventoryReportServiceInterface;
use App\Contracts\Reports\Services\RevenueReportServiceInterface;
use App\Services\Reports\ReportExportManager;
use App\Services\Reports\ReportManager;
use App\Services\Reports\Services\BookingReportService;
use App\Services\Reports\Services\CustomerReportService;
use App\Services\Reports\Services\InventoryReportService;
use App\Services\Reports\Services\RevenueReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class ReportServicesFoundationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @var array<class-string, class-string>
     */
    private const SERVICE_BINDINGS = [
        RevenueReportServiceInterface::class => RevenueReportService::class,
        BookingReportServiceInterface::class => BookingReportService::class,
        CustomerReportServiceInterface::class => CustomerReportService::class,
        InventoryReportServiceInterface::class => InventoryReportService::class,
    ];

    public function test_report_manager_interface_resolves_to_the_report_manager_singleton(): void
    {
        $manager = $this->app->make(ReportManagerInterface::class);

        $this->assertInstanceOf(ReportManager::class, $manager);
        $this->assertSame($manager, $this->app->make(ReportManagerInterface::class));
        $this->assertSame($manager, $this->app->make(ReportManager::class));
    }

    public function test_report_service_interfaces_are_bound_as_singletons(): void
    {
        foreach (self::SERVICE_BINDINGS as $interface => $implementation) {
            $service = $this->app->make($interface);

            $this->assertInstanceOf($implementation, $service);
            $this->assertSame(
                $service,
                $this->app->make($interface),
                "{$interface} must be bound as a singleton.",
            );
        }
    }

    public function test_report_manager_exposes_every_report_service(): void
    {
        $manager = $this->app->make(ReportManagerInterface::class);

        $this->assertSame($this->app->make(RevenueReportServiceInterface::class), $manager->revenueReports());
        $this->assertSame($this->app->make(BookingReportServiceInterface::class), $manager->bookingReports());
        $this->assertSame($this->app->make(CustomerReportServiceInterface::class), $manager->customerReports());
        $this->assertSame($this->app->make(InventoryReportServiceInterface::class), $manager->inventoryReports());
    }

    public function test_report_services_are_injected_into_the_manager_by_contract(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(ReportManager::class))->getConstructor()->getParameters(),
        );

        foreach (array_keys(self::SERVICE_BINDINGS) as $interface) {
            $this->assertContains(
                $interface,
                $parameterTypes,
                "ReportManager must receive {$interface} through constructor injection.",
            );
        }
    }

    public function test_report_consumers_depend_on_the_manager_interface(): void
    {
        foreach ([GenerateReportAction::class, ReportExportManager::class] as $consumer) {
            $parameterTypes = array_map(
                function ($parameter): string {
                    $type = $parameter->getType();

                    $this->assertInstanceOf(ReflectionNamedType::class, $type);

                    return $type->getName();
                },
                (new ReflectionClass($consumer))->getConstructor()->getParameters(),
            );

            $this->assertContains(
                ReportManagerInterface::class,
                $parameterTypes,
                "{$consumer} must depend on ReportManagerInterface, not the concrete manager.",
            );
            $this->assertNotContains(
                ReportManager::class,
                $parameterTypes,
                "{$consumer} must not depend on the concrete ReportManager.",
            );
        }
    }

    public function test_report_services_implement_their_read_only_contracts(): void
    {
        foreach (self::SERVICE_BINDINGS as $interface => $implementation) {
            $this->assertContains(
                $interface,
                class_implements($implementation),
                "{$implementation} must implement {$interface}.",
            );
        }
    }
}
