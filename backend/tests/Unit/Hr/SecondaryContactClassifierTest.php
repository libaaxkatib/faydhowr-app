<?php

namespace Tests\Unit\Hr;

use App\Support\Hr\SecondaryContact\SecondaryContactClassifier;
use PHPUnit\Framework\TestCase;

class SecondaryContactClassifierTest extends TestCase
{
    public function test_name_and_phone_is_split_correctly(): void
    {
        $notes = 'Migrated from Excel HR workbook. Other info: X | Secondary/emergency contact: Hooyo Muna 616605866 | Profile incomplete: Y';
        $result = SecondaryContactClassifier::classify($notes);

        self::assertSame('Hooyo Muna', $result['name']);
        self::assertSame('616605866', $result['phone']);
        self::assertFalse($result['excluded']);
    }

    public function test_phone_only_has_null_name(): void
    {
        $notes = 'Secondary/emergency contact: 618606649';
        $result = SecondaryContactClassifier::classify($notes);

        self::assertNull($result['name']);
        self::assertSame('618606649', $result['phone']);
        self::assertFalse($result['excluded']);
    }

    public function test_name_only_relation_word_is_kept(): void
    {
        $notes = 'Secondary/emergency contact: Habo Safiyo';
        $result = SecondaryContactClassifier::classify($notes);

        self::assertSame('Habo Safiyo', $result['name']);
        self::assertNull($result['phone']);
        self::assertFalse($result['excluded']);
    }

    public function test_name_only_pure_description_is_excluded(): void
    {
        $notes = 'Secondary/emergency contact: Gabar madow caata ah miskin';
        $result = SecondaryContactClassifier::classify($notes);

        self::assertTrue($result['excluded']);
        self::assertNull($result['name']);
        self::assertNull($result['phone']);
    }

    public function test_name_and_phone_where_name_is_a_descriptive_sentence_keeps_phone_only(): void
    {
        $notes = 'Secondary/emergency contact: gabar habo utahay Amira 616605866';
        $result = SecondaryContactClassifier::classify($notes);

        self::assertNull($result['name']);
        self::assertSame('616605866', $result['phone']);
        self::assertFalse($result['excluded']);
        self::assertSame('name_and_phone_sentence_form', $result['reason']);
    }

    public function test_no_marker_is_excluded(): void
    {
        $result = SecondaryContactClassifier::classify('Just a regular note with no marker.');

        self::assertTrue($result['excluded']);
        self::assertSame('no_marker', $result['reason']);
    }

    public function test_never_invents_a_value_when_segment_is_empty(): void
    {
        $notes = 'Secondary/emergency contact:  | Profile incomplete: Y';
        $result = SecondaryContactClassifier::classify($notes);

        self::assertTrue($result['excluded']);
        self::assertNull($result['name']);
        self::assertNull($result['phone']);
    }
}
