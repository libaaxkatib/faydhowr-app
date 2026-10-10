<?php

namespace App\Models;

use App\Enums\ClientCategory;
use App\Enums\ClientStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['name', 'category', 'contact_person', 'phone', 'location', 'status', 'notes', 'created_by'])]
class ClientCompany extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'status' => ClientStatus::class,
            'category' => ClientCategory::class,
        ];
    }

    public function aliases(): HasMany
    {
        return $this->hasMany(ClientCompanyAlias::class);
    }

    public function agreements(): HasMany
    {
        return $this->hasMany(ClientCompanyAgreement::class)->latest('id');
    }

    public function workLocations(): HasMany
    {
        return $this->hasMany(WorkLocation::class);
    }

    public function workAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkAssignment::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'created_by');
    }
}
