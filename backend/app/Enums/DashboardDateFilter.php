<?php

namespace App\Enums;

enum DashboardDateFilter: string
{
    case Today = 'today';

    case Yesterday = 'yesterday';

    case Last7Days = 'last_7_days';

    case Last30Days = 'last_30_days';

    case ThisMonth = 'this_month';

    case LastMonth = 'last_month';

    case CustomDateRange = 'custom_date_range';
}
