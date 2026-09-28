<?php

namespace App\Support\Hr\ExcelMigration;

use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/**
 * Reads REGISTRATION's row highlight colors — a confirmed Fayadhowr business
 * signal, not decoration:
 *
 *   RED (FF0000)     = CANCELED
 *   YELLOW (FFFF00)  = "Damiin ayuu keensaday" (the person HAS already
 *                       brought/provided a guarantor — NOT "guarantor needed")
 *   GREEN (any of 92D050 / 00B050 / 548135 / 53CD93) = "Wuu shaqeeyaa ama
 *                       hore shaqo loo geeyay" (currently works, or was
 *                       previously sent/assigned to work)
 *   EVERY OTHER COLOR, AND NO FILL AT ALL = "Damiin ayaa loo baahan yahay"
 *                       (guarantor needed) — the confirmed default for
 *                       anything that isn't explicitly red/yellow/green.
 *                       This is a business default, not "unknown" — do not
 *                       reintroduce an "ignored/no signal" bucket here.
 *
 * Requires a styled load (NOT setReadDataOnly), which is heavier than the
 * data-only load used everywhere else — kept isolated to this one class so
 * only the REGISTRATION color pass pays that cost.
 */
final class RegistrationColorReader
{
    private const string RED = 'FF0000';

    private const string YELLOW = 'FFFF00';

    /** @var list<string> */
    private const array GREEN_SHADES = ['92D050', '00B050', '548135', '53CD93'];

    /**
     * @return array<int, array{isRed: bool, isYellow: bool, isGreen: bool, greenShade: ?string, isDamiinNeeded: bool, colorContext: string}> keyed by row number
     */
    public function read(string $path, string $sheetName = 'REGISTRATION'): array
    {
        $reader = IOFactory::createReaderForFile($path);
        // Deliberately not setReadDataOnly(true) — fills are style data.
        $spreadsheet = $reader->load($path);

        $sheet = $spreadsheet->getSheetByName($sheetName);

        if ($sheet === null) {
            return [];
        }

        $result = [];
        $highestRow = $sheet->getHighestDataRow();

        for ($row = 1; $row <= $highestRow; $row++) {
            $fill = $sheet->getCell('B'.$row)->getStyle()->getFill();

            if ($fill->getFillType() !== Fill::FILL_SOLID) {
                // No fill at all is itself a confirmed business signal (guarantor
                // needed), not "no signal" — every row gets an entry now.
                $result[$row] = [
                    'isRed' => false, 'isYellow' => false, 'isGreen' => false, 'greenShade' => null,
                    'isDamiinNeeded' => true, 'colorContext' => 'no-fill',
                ];

                continue;
            }

            $rgb = $fill->getStartColor()->getRGB();
            $greenShade = in_array($rgb, self::GREEN_SHADES, true) ? $rgb : null;

            $isRed = $rgb === self::RED;
            $isYellow = $rgb === self::YELLOW;
            $isGreen = $greenShade !== null;
            $isDamiinNeeded = ! $isRed && ! $isYellow && ! $isGreen;

            $colorContext = match (true) {
                $isRed => 'red',
                $isYellow => 'yellow',
                $isGreen => "green:{$greenShade}",
                default => "other:{$rgb}",
            };

            $result[$row] = [
                'isRed' => $isRed, 'isYellow' => $isYellow, 'isGreen' => $isGreen, 'greenShade' => $greenShade,
                'isDamiinNeeded' => $isDamiinNeeded, 'colorContext' => $colorContext,
            ];
        }

        return $result;
    }
}
