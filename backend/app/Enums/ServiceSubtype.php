<?php

namespace App\Enums;

enum ServiceSubtype: string
{
    case FullTime = 'full_time';
    case PartTime = 'part_time';
    case LiveIn = 'live_in';
    case LiveOut = 'live_out';
    case Office = 'office';
    case Hotel = 'hotel';
    case Restaurant = 'restaurant';
    case School = 'school';
    case HospitalClinic = 'hospital_clinic';
    case OtherBusiness = 'other_business';
}
