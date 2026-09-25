<?php

namespace App\Actions\Hr;

use App\Models\PracticalBatch;
use Illuminate\Database\Eloquent\Collection;

class ListPracticalBatchesAction
{
    public function handle(): Collection
    {
        return PracticalBatch::query()
            ->with(['trainer', 'createdBy', 'assessments.employee'])
            ->orderByDesc('batch_date')
            ->get();
    }
}
