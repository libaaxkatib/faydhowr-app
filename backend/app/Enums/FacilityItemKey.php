<?php

namespace App\Enums;

/** The twelve things an inspector checks at a company or workplace (owner requirement, Facility Inspections). */
enum FacilityItemKey: string
{
    case Windows = 'windows';
    case Doors = 'doors';
    case CleaningSupplies = 'cleaning_supplies';
    case Mirrors = 'mirrors';
    case Stairs = 'stairs';
    case Walls = 'walls';
    case Toilets = 'toilets';
    case Beds = 'beds';
    case Plants = 'plants';
    case EmployeeUniforms = 'employee_uniforms';
    case ElectricalSockets = 'electrical_sockets';
    case Fans = 'fans';

    public function label(): string
    {
        return match ($this) {
            self::Windows => 'Windows',
            self::Doors => 'Doors',
            self::CleaningSupplies => 'Cleaning supplies',
            self::Mirrors => 'Mirrors',
            self::Stairs => 'Stairs',
            self::Walls => 'Walls',
            self::Toilets => 'Toilets',
            self::Beds => 'Beds',
            self::Plants => 'Plants',
            self::EmployeeUniforms => 'Employee uniforms',
            self::ElectricalSockets => 'Electrical sockets',
            self::Fans => 'Fans',
        };
    }

    /** @return list<string> */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
