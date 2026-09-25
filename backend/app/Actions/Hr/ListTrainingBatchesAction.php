<?php

namespace App\Actions\Hr;

use App\Models\TrainingBatch;
use Illuminate\Database\Eloquent\Collection;

class ListTrainingBatchesAction
{
    public function handle(): Collection
    {
        return TrainingBatch::query()
            ->with(['trainer', 'createdBy', 'participants.employee'])
            ->orderByDesc('batch_date')
            ->get();
    }
}
