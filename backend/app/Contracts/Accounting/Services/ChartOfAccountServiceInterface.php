<?php

namespace App\Contracts\Accounting\Services;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountStatus;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use App\Models\Account;
use Illuminate\Database\Eloquent\Collection;

/**
 * Contract for the chart of accounts service of the Accounting module.
 * The chart of accounts is the master list of financial accounts that
 * journal entries post against.
 *
 * Account structure:
 *
 * - code: string — unique hierarchical account code (e.g. "1100")
 * - name: string — human-readable account name
 * - account_type: {@see AccountType}
 * - account_category: {@see AccountCategory}
 * - parent_account: ?self — optional parent for hierarchical grouping
 * - is_group: bool — group accounts hold children and never receive
 *   postings directly
 * - normal_balance: {@see NormalBalance} — derived from the category
 * - status: {@see AccountStatus}
 */
interface ChartOfAccountServiceInterface
{
    /**
     * Every account in the chart, ordered by code.
     *
     * @return Collection<int, Account>
     */
    public function accounts(): Collection;
}
