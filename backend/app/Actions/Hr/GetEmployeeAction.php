<?php

namespace App\Actions\Hr;

use App\Models\Employee;

class GetEmployeeAction
{
    public function handle(Employee $employee): Employee
    {
        return $employee->load([
            'category',
            'department',
            'position',
            'statusHistories.changedBy',
            'practicalAssessments.assessedBy',
            'documents.admin',
            'activeWorkAssignments.workLocation.clientCompany',
            'activeWorkAssignments.position',
            'workAssignments.workLocation.clientCompany',
            'workAssignments.position',
        ]);
    }
}
