<?php

namespace App\Enums;

/**
 * The three stages the Issue #12 historical-completion rule covers. Every
 * Green ∪ Waiting List employee gets exactly one row per stage — never a
 * partial subset — see EmployeeHistoricalCompletion.
 */
enum EmployeeHistoricalCompletionStage: string
{
    case Training = 'training';
    case Practical = 'practical';
    case Uniform = 'uniform';
}
