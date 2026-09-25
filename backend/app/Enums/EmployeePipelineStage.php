<?php

namespace App\Enums;

/**
 * docs/HRM_MARKETING_SRS.md HR §4/§12: tracks the fine-grained pre-Waiting
 * pipeline independently of the existing EmployeeStatus enum, which keeps
 * its original meaning untouched (see the HRM Phase 1 plan's architecture
 * note). Only meaningful while status = Applicant; cleared to null once a
 * Practical Decision of Approved moves the employee to status = Waiting.
 */
enum EmployeePipelineStage: string
{
    case DamiinNeeded = 'damiin_needed';
    case ContractPending = 'contract_pending';
    case UniformPending = 'uniform_pending';
    case NeedTraining = 'need_training';
    case NeedPractical = 'need_practical';
    case PracticalRepeat = 'practical_repeat';
    case Rejected = 'rejected';

    public function label(): string
    {
        return match ($this) {
            self::DamiinNeeded => 'Damiin Needed',
            self::ContractPending => 'Contract Pending',
            self::UniformPending => 'Uniform Pending',
            self::NeedTraining => 'Need Training',
            self::NeedPractical => 'Need Practical',
            self::PracticalRepeat => 'Practical Repeat',
            self::Rejected => 'Rejected',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
