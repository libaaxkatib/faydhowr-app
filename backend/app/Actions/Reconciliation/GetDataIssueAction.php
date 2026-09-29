<?php

namespace App\Actions\Reconciliation;

use App\Models\DataIssue;

class GetDataIssueAction
{
    public function handle(DataIssue $dataIssue): DataIssue
    {
        return $dataIssue->load([
            'creator',
            'resolver',
            'affectedRecords.recordable',
            'auditLogs.admin',
        ]);
    }
}
