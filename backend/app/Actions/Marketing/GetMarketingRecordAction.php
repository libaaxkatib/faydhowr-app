<?php

namespace App\Actions\Marketing;

use App\Models\MarketingRecord;

class GetMarketingRecordAction
{
    public function handle(MarketingRecord $record): MarketingRecord
    {
        return $record->load([
            'assignedTeam',
            'assignedAdmin',
            'broughtByAdmin',
            'xarunDetail',
            'projectDetail',
            'followUps.assignedAdmin',
            'followUps.histories.performedBy',
            'quotations.createdBy',
        ]);
    }
}
