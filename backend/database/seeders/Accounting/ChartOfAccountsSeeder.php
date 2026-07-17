<?php

namespace Database\Seeders\Accounting;

use App\Services\Accounting\DefaultChartOfAccountsService;
use Illuminate\Database\Seeder;

class ChartOfAccountsSeeder extends Seeder
{
    /**
     * Seed the default chart of accounts.
     */
    public function run(DefaultChartOfAccountsService $defaultChartOfAccounts): void
    {
        $defaultChartOfAccounts->seed();
    }
}
