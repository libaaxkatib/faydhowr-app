<?php

namespace App\Enums;

enum ServiceMode: string
{
    case OneTime = 'one_time';
    case MonthlyContract = 'monthly_contract';
}
