<?php

namespace App\Http\Resources\Api\V1\Admin\Hr;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Read-only Training History for the Employee Profile - built from the
 * existing TrainingBatchParticipant/TrainingBatch data, no new table.
 */
class EmployeeTrainingHistoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $batch = $this->trainingBatch;

        return [
            'id' => $this->id,
            'training_batch_id' => $this->training_batch_id,
            'batch_number' => $batch?->batch_number,
            'batch_date' => $batch?->batch_date?->toDateString(),
            'start_time' => $batch?->start_time,
            'end_time' => $batch?->end_time,
            'team_or_group' => $batch?->team_or_group,
            'trainer_name' => $batch?->relationLoaded('trainer') ? $batch->trainer?->full_name : null,
            'location' => $batch?->location,
            'result' => $this->result->value,
            'notes' => $this->notes,
        ];
    }
}
