<?php

namespace App\Enums\Marketing;

/**
 * docs/HRM_MARKETING_SRS.md §6/§36: "XARUN != PROJECT" — two distinct
 * workflows, never one generic form.
 */
enum MarketingRecordType: string
{
    case Xarun = 'xarun';
    case Project = 'project';
}
