<?php

namespace App\Actions\Hr;

use App\Models\Employee;

class GetEmployeeAction
{
    public function handle(Employee $employee): Employee
    {
        $employee->load([
            'category',
            'department',
            'position',
            'statusHistories.changedBy',
            'practicalAssessments.assessedBy',
            'documents.admin',
            'documents.category',
            'documents.verifiedBy',
            'activeWorkAssignments.workLocation.clientCompany',
            'activeWorkAssignments.position',
            'workAssignments.workLocation.clientCompany',
            'workAssignments.position',
            'guarantor.verifiedBy',
            'guarantor.createdBy',
            'currentContract.signedDocument',
            'contracts.signedDocument',
            'uniform.confirmedBy',
            'separations.separatedBy',
            'historicalCompletions',
            'attendances.recordedBy',
            'leaves.recordedBy',
            'performanceReviews.reviewedBy',
            'payments.paidBy',
            'penalties.recordedBy',
            'advances.recordedBy',
            'trainingParticipations.trainingBatch.trainer',
        ]);

        $employee->setRelation(
            'trainingParticipations',
            $employee->trainingParticipations->sortByDesc(fn ($participation) => $participation->trainingBatch?->batch_date)->values(),
        );

        return $employee;
    }
}
