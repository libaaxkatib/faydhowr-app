<?php

namespace Tests\Unit\Hr;

use App\Support\Hr\ExcelMigration\PhoneNormalizer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Issue #8: the old isSomaliMobileFormat() only accepted the "6" prefix,
 * incorrectly rejecting real Hormuud (77), Amtel (71), and Golis (90)
 * numbers as invalid format.
 */
class PhoneNormalizerTest extends TestCase
{
    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function phoneProvider(): array
    {
        return [
            'Hormuud 61' => ['615123456', true],
            'Hormuud 77' => ['771306212', true],
            'Somtel 62' => ['621234567', true],
            'Somtel 65' => ['651234567', true],
            'Somtel 66' => ['661234567', true],
            'Telesom 63' => ['631234567', true],
            'SomLink 64' => ['641234567', true],
            'SomNet 68' => ['681234567', true],
            'NationLink 69' => ['691234567', true],
            'Amtel 71' => ['711234567', true],
            'Golis 90' => ['901234567', true],
            'too short' => ['61512345', false],
            'too long' => ['6151234567', false],
            'wrong prefix' => ['512345678', false],
        ];
    }

    #[DataProvider('phoneProvider')]
    public function test_normalize_accepts_real_somali_mobile_prefixes(string $primaryDigits, bool $expectedValid): void
    {
        $result = PhoneNormalizer::normalize($primaryDigits);

        self::assertSame($expectedValid, $result['isValidFormat']);
        self::assertSame($primaryDigits, $result['primary']);
    }

    public function test_normalize_strips_non_digit_characters_before_validating_format(): void
    {
        $result = PhoneNormalizer::normalize('61a123456');

        self::assertSame('61123456', $result['primary']);
        self::assertFalse($result['isValidFormat']);
    }

    public function test_normalize_recovers_the_real_reported_rejected_number(): void
    {
        // The exact real production number from the Issue #8 investigation
        // (EMP-003154, Hawo Mohamed Ahmed) — previously rejected by the "6"-only check.
        $result = PhoneNormalizer::normalize('771306212');

        self::assertTrue($result['isValidFormat']);
        self::assertSame('771306212', $result['primary']);
    }

    public function test_normalize_strips_leading_zero_and_country_code_before_validating(): void
    {
        self::assertTrue(PhoneNormalizer::normalize('0771306212')['isValidFormat']);
        self::assertTrue(PhoneNormalizer::normalize('252771306212')['isValidFormat']);
    }
}
