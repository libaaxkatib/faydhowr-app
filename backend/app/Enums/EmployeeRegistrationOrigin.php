<?php

namespace App\Enums;

/**
 * How a person came into the HR register. It decides which lifecycle rules apply (see EmployeeRegistrationWorkflow):
 *
 *  - Existing     registered before the strict workflow existed. Always manageable one by one: an authorized user may move the person
 *                 to a stage (Waiting List, Training, Practice ...) with a reason; no registration steps are required of them.
 *  - New          registered through the workflow. Must complete guarantor, contract, uniform, training and practice (each with its
 *                 record) before Waiting List, Active or a company assignment.
 *  - OfficeStaff  registered directly as Office Staff (hired, not recruited), so the recruitment pipeline does not apply.
 */
enum EmployeeRegistrationOrigin: string
{
    case Existing = 'existing';
    case New = 'new';
    case OfficeStaff = 'office_staff';

    public function label(): string
    {
        return match ($this) {
            self::Existing => 'Existing record',
            self::New => 'New registration',
            self::OfficeStaff => 'Office staff',
        };
    }
}
