<?php

namespace App\Models;

use Database\Factories\PasswordResetTokenFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

#[Fillable([
    'subject_type',
    'subject_id',
    'token_hash',
    'expires_at',
    'used_at',
    'created_at',
])]
class PasswordResetToken extends Model
{
    /** @use HasFactory<PasswordResetTokenFactory> */
    use HasFactory;

    public const string SUBJECT_USER = 'user';

    public $timestamps = false;

    public function isUsable(): bool
    {
        return $this->used_at === null && $this->expires_at->isFuture();
    }

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'subject_id' => 'integer',
            'expires_at' => 'datetime',
            'used_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }
}
