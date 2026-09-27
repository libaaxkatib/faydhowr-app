<?php

namespace App\Models;

use Database\Factories\SettingsBackupFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A settings backup snapshot, stored in the database (not on disk) so it
 * survives redeploys/restarts. Immutable once created: nothing ever updates
 * a row, so there is no updated_at.
 */
#[Fillable([
    'id',
    'snapshot',
    'size_bytes',
    'created_by',
    'created_at',
])]
class SettingsBackup extends Model
{
    /** @use HasFactory<SettingsBackupFactory> */
    use HasFactory;

    protected $primaryKey = 'id';

    protected $keyType = 'string';

    public $incrementing = false;

    public $timestamps = false;

    /**
     * @return array<string, string|class-string>
     */
    protected function casts(): array
    {
        return [
            'snapshot' => 'array',
            'created_at' => 'datetime',
        ];
    }
}
