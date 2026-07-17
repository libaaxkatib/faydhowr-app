<?php

namespace App\Models;

use App\Enums\Accounting\AccountCategory;
use App\Enums\Accounting\AccountStatus;
use App\Enums\Accounting\AccountType;
use App\Enums\Accounting\NormalBalance;
use Database\Factories\AccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable([
    'code',
    'name',
    'account_type',
    'account_category',
    'parent_account_id',
    'is_group',
    'normal_balance',
    'status',
])]
class Account extends Model
{
    /** @use HasFactory<AccountFactory> */
    use HasFactory;

    /**
     * @return BelongsTo<Account, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(Account::class, 'parent_account_id');
    }

    /**
     * @return HasMany<Account, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(Account::class, 'parent_account_id');
    }

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'account_type' => AccountType::class,
            'account_category' => AccountCategory::class,
            'is_group' => 'boolean',
            'normal_balance' => NormalBalance::class,
            'status' => AccountStatus::class,
        ];
    }
}
