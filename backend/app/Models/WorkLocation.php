<?php

namespace App\Models;

use App\Enums\ClientStatus;
use App\Enums\LocationType;
use App\Enums\WorkAssignmentStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['client_company_id', 'location_type', 'name', 'location', 'contact_person', 'phone', 'capacity', 'status', 'notes'])]
class WorkLocation extends Model
{
    use HasFactory;

    protected function casts(): array
    {
        return [
            'location_type' => LocationType::class,
            'status' => ClientStatus::class,
            'capacity' => 'integer',
        ];
    }

    public function clientCompany(): BelongsTo
    {
        return $this->belongsTo(ClientCompany::class);
    }

    public function workAssignments(): HasMany
    {
        return $this->hasMany(EmployeeWorkAssignment::class);
    }

    public function workforceRequests(): HasMany
    {
        return $this->hasMany(WorkforceRequest::class);
    }

    public function activeAssignmentsCount(): int
    {
        return $this->workAssignments()->where('status', WorkAssignmentStatus::Active)->count();
    }

    public function availableSlots(): ?int
    {
        if ($this->capacity === null) {
            return null;
        }

        return max(0, $this->capacity - $this->activeAssignmentsCount());
    }
}
