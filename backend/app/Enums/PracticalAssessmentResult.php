<?php

namespace App\Enums;

enum PracticalAssessmentResult: string
{
    case Pass = 'pass';
    case Fail = 'fail';
    case Pending = 'pending';
}
