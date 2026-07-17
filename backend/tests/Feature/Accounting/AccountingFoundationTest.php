<?php

namespace Tests\Feature\Accounting;

use App\Contracts\Accounting\AccountingManagerInterface;
use App\Contracts\Accounting\Services\AccountingPeriodServiceInterface;
use App\Contracts\Accounting\Services\ChartOfAccountServiceInterface;
use App\Contracts\Accounting\Services\FinancialReportServiceInterface;
use App\Contracts\Accounting\Services\JournalEntryServiceInterface;
use App\Contracts\Accounting\Services\LedgerServiceInterface;
use App\Contracts\Accounting\Services\TrialBalanceServiceInterface;
use App\Services\Accounting\AccountingManager;
use App\Services\Accounting\Services\AccountingPeriodService;
use App\Services\Accounting\Services\ChartOfAccountService;
use App\Services\Accounting\Services\FinancialReportService;
use App\Services\Accounting\Services\JournalEntryService;
use App\Services\Accounting\Services\LedgerService;
use App\Services\Accounting\Services\TrialBalanceService;
use ReflectionClass;
use ReflectionNamedType;
use Tests\TestCase;

class AccountingFoundationTest extends TestCase
{
    /**
     * @var array<class-string, class-string>
     */
    private const SERVICE_BINDINGS = [
        ChartOfAccountServiceInterface::class => ChartOfAccountService::class,
        JournalEntryServiceInterface::class => JournalEntryService::class,
        LedgerServiceInterface::class => LedgerService::class,
        FinancialReportServiceInterface::class => FinancialReportService::class,
        AccountingPeriodServiceInterface::class => AccountingPeriodService::class,
        TrialBalanceServiceInterface::class => TrialBalanceService::class,
    ];

    public function test_accounting_manager_interface_resolves_to_the_accounting_manager_singleton(): void
    {
        $manager = $this->app->make(AccountingManagerInterface::class);

        $this->assertInstanceOf(AccountingManager::class, $manager);
        $this->assertSame($manager, $this->app->make(AccountingManagerInterface::class));
    }

    public function test_accounting_service_interfaces_are_bound_as_singletons(): void
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

    public function test_accounting_manager_exposes_every_accounting_service(): void
    {
        $manager = $this->app->make(AccountingManagerInterface::class);

        $this->assertSame($this->app->make(ChartOfAccountServiceInterface::class), $manager->chartOfAccounts());
        $this->assertSame($this->app->make(JournalEntryServiceInterface::class), $manager->journalEntries());
        $this->assertSame($this->app->make(LedgerServiceInterface::class), $manager->ledger());
        $this->assertSame($this->app->make(FinancialReportServiceInterface::class), $manager->financialReports());
        $this->assertSame($this->app->make(AccountingPeriodServiceInterface::class), $manager->accountingPeriods());
        $this->assertSame($this->app->make(TrialBalanceServiceInterface::class), $manager->trialBalance());
    }

    public function test_accounting_services_are_injected_into_the_manager_by_contract(): void
    {
        $parameterTypes = array_map(
            function ($parameter): string {
                $type = $parameter->getType();

                $this->assertInstanceOf(ReflectionNamedType::class, $type);

                return $type->getName();
            },
            (new ReflectionClass(AccountingManager::class))->getConstructor()->getParameters(),
        );

        $this->assertSame(array_keys(self::SERVICE_BINDINGS), $parameterTypes);
    }

    public function test_accounting_services_implement_their_contracts(): void
    {
        foreach (self::SERVICE_BINDINGS as $interface => $implementation) {
            $this->assertContains(
                $interface,
                class_implements($implementation),
                "{$implementation} must implement {$interface}.",
            );
        }
    }

    public function test_accounting_manager_exposes_services_through_interface_return_types(): void
    {
        foreach ([
            'chartOfAccounts' => ChartOfAccountServiceInterface::class,
            'journalEntries' => JournalEntryServiceInterface::class,
            'ledger' => LedgerServiceInterface::class,
            'financialReports' => FinancialReportServiceInterface::class,
            'accountingPeriods' => AccountingPeriodServiceInterface::class,
            'trialBalance' => TrialBalanceServiceInterface::class,
        ] as $method => $interface) {
            $type = (new ReflectionClass(AccountingManagerInterface::class))
                ->getMethod($method)
                ->getReturnType();

            $this->assertInstanceOf(ReflectionNamedType::class, $type);
            $this->assertSame(
                $interface,
                $type->getName(),
                "AccountingManagerInterface::{$method}() must return {$interface}.",
            );
        }
    }
}
