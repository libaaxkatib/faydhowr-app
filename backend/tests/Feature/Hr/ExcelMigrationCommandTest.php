<?php

namespace Tests\Feature\Hr;

use App\Enums\EmployeePipelineStage;
use App\Enums\EmployeeStatus;
use App\Models\Admin;
use App\Models\Employee;
use App\Support\Hr\ExcelMigration\CancellationReasonExtractor;
use App\Support\Hr\ExcelMigration\CategoryMatcher;
use App\Support\Hr\ExcelMigration\CookingSpecializationMatcher;
use App\Support\Hr\ExcelMigration\DateParser;
use App\Support\Hr\ExcelMigration\ExcelMigrationService;
use App\Support\Hr\ExcelMigration\HomeCleaningWorkTypeMatcher;
use App\Support\Hr\ExcelMigration\NameMatcher;
use App\Support\Hr\ExcelMigration\PhoneNormalizer;
use App\Support\Hr\ExcelMigration\RegistrationColorReader;
use App\Support\Hr\ExcelMigration\RegistrationDateInferrer;
use App\Support\Hr\ExcelMigration\ResolvedPerson;
use App\Support\Hr\ExcelMigration\StageDictionary;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use ReflectionClass;
use Tests\TestCase;

class ExcelMigrationCommandTest extends TestCase
{
    use RefreshDatabase;

    private string $fixturePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->fixturePath = tempnam(sys_get_temp_dir(), 'hr_excel_fixture_').'.xlsx';
        $this->buildFixtureWorkbook($this->fixturePath);
    }

    protected function tearDown(): void
    {
        if (is_file($this->fixturePath)) {
            unlink($this->fixturePath);
        }

        parent::tearDown();
    }

    public function test_command_exists_and_dry_run_succeeds(): void
    {
        $this->artisan('hr:migrate-from-excel', ['path' => $this->fixturePath, '--dry-run' => true])
            ->assertExitCode(0);
    }

    public function test_missing_file_fails_safely(): void
    {
        $this->artisan('hr:migrate-from-excel', ['path' => '/no/such/file.xlsx'])
            ->assertExitCode(1);
    }

    public function test_commit_flag_writes_ready_to_import_people_and_is_idempotent_on_rerun(): void
    {
        $admin = Admin::factory()->create();

        $this->artisan('hr:migrate-from-excel', [
            'path' => $this->fixturePath,
            '--commit' => true,
            '--actor' => (string) $admin->id,
        ])->assertExitCode(0);

        $createdCount = Employee::query()->count();
        $this->assertGreaterThan(0, $createdCount, 'Expected the fixture workbook to produce at least one ready-to-import person.');
        $this->assertDatabaseCount('employee_status_histories', $createdCount);
        $this->assertSame($createdCount, Employee::query()->where('created_by', $admin->id)->count());

        // Re-running --commit against the same workbook must never duplicate anyone WHO HAS A
        // REAL PHONE (the idempotency key). A no-phone person has no such key and is always
        // (re-)created on a re-run — a documented limitation, see ExcelMigrationCommitter —
        // so this only asserts the phone-holding population stays stable.
        $withPhoneCountBefore = Employee::query()->whereNotNull('phone')->count();

        $this->artisan('hr:migrate-from-excel', ['path' => $this->fixturePath, '--commit' => true])
            ->assertExitCode(0);

        $this->assertSame($withPhoneCountBefore, Employee::query()->whereNotNull('phone')->count());
    }

    public function test_commit_flag_with_unknown_actor_fails_safely(): void
    {
        $this->artisan('hr:migrate-from-excel', [
            'path' => $this->fixturePath,
            '--commit' => true,
            '--actor' => '999999',
        ])->assertExitCode(1);

        $this->assertDatabaseCount('employees', 0);
    }

    public function test_dry_run_never_writes_any_hr_records(): void
    {
        $this->artisan('hr:migrate-from-excel', ['path' => $this->fixturePath, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertDatabaseCount('employees', 0);
        $this->assertDatabaseCount('employee_status_histories', 0);
        $this->assertDatabaseCount('employee_guarantors', 0);
        $this->assertDatabaseCount('employee_separations', 0);
        $this->assertDatabaseCount('departments', 0);
        $this->assertDatabaseCount('positions', 0);
    }

    public function test_phone_normalization(): void
    {
        $this->assertSame('615123456', PhoneNormalizer::normalize('615123456')['primary']);
        $this->assertSame('615123456', PhoneNormalizer::normalize('0615123456')['primary']);
        $this->assertSame('615123456', PhoneNormalizer::normalize('+252615123456')['primary']);
        $this->assertTrue(PhoneNormalizer::normalize('615123456')['isValidFormat']);

        $both = PhoneNormalizer::normalize('618047273/616284852');
        $this->assertSame('618047273', $both['primary']);
        $this->assertSame('616284852', $both['alternate']);

        $empty = PhoneNormalizer::normalize('');
        $this->assertNull($empty['primary']);
        $this->assertFalse($empty['isValidFormat']);

        $garbage = PhoneNormalizer::normalize('n/a');
        $this->assertFalse($garbage['isValidFormat']);
    }

    public function test_name_matcher_same_phone_first_name_shared_is_compatible(): void
    {
        $this->assertTrue(NameMatcher::areCompatible('Ahmed Ali Warsame', 'Ahmed Ali'));
        $this->assertFalse(NameMatcher::areCompatible('Ahmed Ali Warsame', 'Fatima Yusuf Omar'));
    }

    public function test_name_matcher_no_corroboration_requires_near_identical_names(): void
    {
        // Same first name only is common in this population and must NOT match without a phone.
        $this->assertFalse(NameMatcher::isLikelySamePersonByNameAlone('Farxiyo Abdulle Cosoble', 'Farxiyo Mohamed Ahmed'));
        $this->assertTrue(NameMatcher::isLikelySamePersonByNameAlone('Farxiyo Abdulle Cosoble', 'Farxiyo Abdulle Cosoble'));
    }

    public function test_stage_dictionary_need_training_variants(): void
    {
        foreach (['need training', 'need tarining', 'need traninig', 'nedd traning'] as $variant) {
            $resolution = StageDictionary::resolve('REGISTRATION', $variant);
            $this->assertSame(EmployeeStatus::Applicant, $resolution->status);
            $this->assertSame(EmployeePipelineStage::NeedTraining, $resolution->pipelineStage);
            $this->assertFalse($resolution->needsManualReview);
        }
    }

    /**
     * CONFIRMED business rule: guarantor-need is tracked as an INDEPENDENT flag on
     * ResolvedPerson (see ExcelMigrationService), never as a pipelineStage — a
     * pipelineStage competes by rank against other stages and would silently lose
     * that competition. Sheet membership still resolves to Applicant status; it
     * must NOT claim a pipelineStage of DamiinNeeded any more.
     */
    public function test_stage_dictionary_damiin_needed_sheet(): void
    {
        $resolution = StageDictionary::resolve('DAMIIN WALI KEENIN', 'anything');
        $this->assertSame(EmployeeStatus::Applicant, $resolution->status);
        $this->assertNull($resolution->pipelineStage);
    }

    public function test_stage_dictionary_cancelled_sheet(): void
    {
        $resolution = StageDictionary::resolve('KUWA LA KANSALEY', 'need training');
        $this->assertSame(EmployeeStatus::Inactive, $resolution->status);
        $this->assertTrue($resolution->isCancellation);
    }

    public function test_stage_dictionary_waiting_sheet(): void
    {
        $resolution = StageDictionary::resolve('Waiting List', null);
        $this->assertSame(EmployeeStatus::Waiting, $resolution->status);
    }

    public function test_stage_dictionary_unknown_value_requires_manual_review(): void
    {
        $resolution = StageDictionary::resolve('REGISTRATION', 'Trained worked Atlantic');
        $this->assertTrue($resolution->needsManualReview);
        $this->assertNull($resolution->status);
    }

    public function test_date_parser_handles_the_three_known_formats_and_nothing_else(): void
    {
        $this->assertNotNull(DateParser::parse('44081')); // Excel serial
        $this->assertNotNull(DateParser::parse('22/11/2018'));
        $this->assertNotNull(DateParser::parse('25-11-2018'));
        $this->assertNull(DateParser::parse(''));
        $this->assertNull(DateParser::parse('not a date'));
        $this->assertNull(DateParser::parse(null));
    }

    /**
     * CONFIRMED-by-inspection: these are real, repeated format variants found in the
     * actual REGISTRATION sheet's Date column during the 22-unparseable-date audit —
     * not missing dates at all, just formats the parser didn't yet recognize.
     */
    public function test_date_parser_handles_confirmed_real_format_variants(): void
    {
        // Backslash as separator — observed 10 times, identical raw value "3\9\2022".
        $this->assertSame('2022-09-03', DateParser::parse('3\9\2022')?->toDateString());

        // Mixed backslash and forward slash in the same value.
        $this->assertSame('2023-06-21', DateParser::parse('21\06/2023')?->toDateString());
        $this->assertSame('2024-05-15', DateParser::parse('15\5/2024')?->toDateString());

        // Missing separator between month and year — "D/MMYYYY".
        $this->assertSame('2023-03-11', DateParser::parse('11/032023')?->toDateString());
        $this->assertSame('2023-03-11', DateParser::parse('11/032023 ')?->toDateString());
        $this->assertSame('2023-06-20', DateParser::parse('20/062023')?->toDateString());

        // 5-digit year with a redundant zero after "20" — observed exactly once.
        $this->assertSame('2023-09-02', DateParser::parse('2/9/20023')?->toDateString());

        // Still never guesses at genuinely unparseable text.
        $this->assertNull(DateParser::parse('transfer'));
        $this->assertNull(DateParser::parse(' '));
    }

    /**
     * CONFIRMED business decision: when a REGISTRATION row's own Date cell cannot be
     * parsed at all, infer the closest reliable date from the surrounding sequence —
     * but ONLY when the nearest valid date immediately before AND after this row agree
     * exactly. Any disagreement, or missing evidence on either side, must stay null —
     * never a fabricated or single-sided guess. Modeled directly on 3 real cases found
     * in the actual workbook (row 92, row 2376, row 2752).
     */
    public function test_registration_date_inferrer_only_resolves_when_both_sides_agree(): void
    {
        // Unanimous immediate neighbors on both sides — the one real case (row 2752)
        // that DID resolve: 3 rows before and 3 rows after, all the same date.
        $unanimous = [
            97 => '01/04/2025', 98 => '01/04/2025', 99 => '01/04/2025',
            100 => ' ', // the target row itself — blank
            101 => '01/04/2025', 102 => '01/04/2025', 103 => '01/04/2025',
        ];
        $result = RegistrationDateInferrer::infer(100, $unanimous);
        $this->assertNotNull($result);
        $this->assertSame('2025-04-01', $result['date']->toDateString());
        $this->assertSame(99, $result['beforeRow']);
        $this->assertSame(101, $result['afterRow']);

        // Nearest before/after DISAGREE — real case (row 2376): must never guess which
        // one is right, stays null.
        $disagreeing = [
            50 => '10/07/2024', 51 => null,
            52 => null, // target
            53 => null, 54 => '10/12/2024',
        ];
        $this->assertNull(RegistrationDateInferrer::infer(52, $disagreeing));

        // No valid date at all on one side within range — real case (row 92): stays null.
        $oneSidedOnly = [
            10 => '28/09/2021', 11 => null,
            12 => null, // target
            13 => null, 14 => null, 15 => null,
        ];
        $this->assertNull(RegistrationDateInferrer::infer(12, $oneSidedOnly));

        // No rows at all around the target — stays null, never fabricated.
        $this->assertNull(RegistrationDateInferrer::infer(500, []));
    }

    public function test_category_matcher_only_matches_existing_categories(): void
    {
        $existing = ['General Cleaning', 'Cooking', 'Home Team'];

        $this->assertSame('Cooking', CategoryMatcher::match('Chef', $existing)['matched']);
        $this->assertSame('General Cleaning', CategoryMatcher::match('Nadaafad', $existing)['matched']);

        // "Waiter" is a real job title but not in the existing-categories list passed in —
        // must not be proposed, even though the word itself is unambiguous.
        $this->assertNull(CategoryMatcher::match('Waiter', $existing)['matched']);
        $this->assertNull(CategoryMatcher::match('Totally unrelated text', $existing)['matched']);
    }

    /**
     * CONFIRMED business rule: Xaafad/Xafad/xafad/xafaad (any casing) always means
     * Home Cleaning — mapped to the existing "Home Team" category — and must
     * NEVER fall through to General Cleaning, even though "cleaning" alone does.
     */
    public function test_category_matcher_xafad_variants_always_map_to_home_team_not_general_cleaning(): void
    {
        $existing = ['General Cleaning', 'Cooking', 'Home Team'];

        foreach ([
            'Xaafad', 'XAAFAD', 'Xafad', 'xafad', 'xaafad', 'Cleaning Xafad maalin',
            'xafad jiif', 'Cleaning Xafad', 'xafaad', 'Xafaad', 'XAFAAD', 'xafaad jiif',
        ] as $variant) {
            $this->assertSame('Home Team', CategoryMatcher::match($variant, $existing)['matched'], "Failed for variant: {$variant}");
        }

        // Without Home Team existing, xafad must not silently fall back to General Cleaning either.
        $this->assertNull(CategoryMatcher::match('Xafad', ['General Cleaning', 'Cooking'])['matched']);
    }

    /**
     * CONFIRMED business rule: "Post Construction" (any spelling/format variant actually
     * observed in the workbook) always means General Cleaning — never a new category.
     */
    public function test_category_matcher_post_construction_variants_map_to_general_cleaning(): void
    {
        $existing = ['General Cleaning', 'Cooking', 'Home Team'];

        foreach ([
            'post construction', 'Post Construction', 'POST CONSTRUCTION', 'post-construction',
            'post constraction', 'post constaraction', 'post construction worker',
            'post costruction', 'post contraction', 'post constru ction', 'post constuction',
        ] as $variant) {
            $this->assertSame('General Cleaning', CategoryMatcher::match($variant, $existing)['matched'], "Failed for variant: {$variant}");
        }

        // Never proposed as a new "Post Construction" category — General Cleaning is the only outcome.
        $this->assertNotSame('Post Construction', CategoryMatcher::match('post construction', $existing)['matched']);
    }

    /**
     * CONFIRMED-by-inspection: these letter-transposed/garbled Home Cleaning spellings
     * were actually observed (repeated) in the real workbook — not a blind fuzzy guess,
     * same convention as the post-construction variant list above.
     */
    public function test_category_matcher_home_cleaning_observed_typo_variants(): void
    {
        $existing = ['General Cleaning', 'Cooking', 'Home Team'];

        foreach (['xadfad', 'xaadaf', 'faxad', 'xaafaad', 'home cleaning', 'Home Cleaning Jiif'] as $variant) {
            $this->assertSame('Home Team', CategoryMatcher::match($variant, $existing)['matched'], "Failed for variant: {$variant}");
        }
    }

    /**
     * CONFIRMED business decision: "General Cleanin" (missing g) and "General
     * Cleaninng" (extra n) are simple typos, exactly 2 real occurrences — normalize
     * both to the existing "General Cleaning" category, never a new category.
     */
    public function test_category_matcher_general_cleaning_observed_typos(): void
    {
        $existing = ['General Cleaning', 'Cooking', 'Home Team'];

        $this->assertSame('General Cleaning', CategoryMatcher::match('General Cleanin', $existing)['matched']);
        $this->assertSame('General Cleaning', CategoryMatcher::match('General Cleaninng', $existing)['matched']);
    }

    /**
     * CONFIRMED business decision: "Supervisor" is now a real production category — Job
     * text clearly saying "supervisor" (any casing) maps to it, a confident keyword
     * match, not a guess.
     */
    public function test_category_matcher_supervisor_category(): void
    {
        $existing = ['General Cleaning', 'Cooking', 'Home Team', 'Supervisor'];

        $result = CategoryMatcher::match('supervisor', $existing);
        $this->assertSame('Supervisor', $result['matched']);
        $this->assertTrue($result['isConfident']);

        $this->assertSame('Supervisor', CategoryMatcher::match('SUPErvisor', $existing)['matched']);

        // Never proposed if "Supervisor" isn't actually in the existing category list.
        $this->assertNull(CategoryMatcher::match('supervisor', ['General Cleaning'])['matched']);
    }

    /**
     * CONFIRMED-by-inspection: "cunto karin"/"cunta karin" (with an "n") is the far more
     * common real spelling in the Cooking Centre sheet (8 occurrences) — the pre-existing
     * "karis" (with an "s") keyword alone silently missed this majority variant. "baarista"
     * is the real double-vowel spelling observed in Waiters Centre.
     */
    public function test_category_matcher_cooking_and_barista_observed_variants(): void
    {
        $existing = ['General Cleaning', 'Cooking', 'Home Team', 'Barista'];

        $this->assertSame('Cooking', CategoryMatcher::match('cunto karin', $existing)['matched']);
        $this->assertSame('Cooking', CategoryMatcher::match('cunta karin', $existing)['matched']);
        $this->assertSame('Barista', CategoryMatcher::match('baarista', $existing)['matched']);
    }

    /**
     * CONFIRMED business structure: under Home Team, "jiif" means Full Time and
     * "malin"/"maalin" means Part Time — structured, never guessed when both or
     * neither are mentioned.
     */
    public function test_home_cleaning_work_type_matcher(): void
    {
        foreach (['Xafad jiif', 'xaafad jiif', 'xafaad jiif', 'jiif', 'xafad jif', 'xaafadjiif'] as $job) {
            $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match($job), "Failed for: {$job}");
        }

        foreach (['Xafad malin', 'xaafad maalin', 'xafad maalin', 'maalin', 'xafad day', 'xafad mlin'] as $job) {
            $this->assertSame(HomeCleaningWorkTypeMatcher::PART_TIME_MAALIN, HomeCleaningWorkTypeMatcher::match($job), "Failed for: {$job}");
        }

        // CONFIRMED business decision: both mentioned together, or neither mentioned at
        // all, both default to Full Time – Jiif (never left unspecified, never a third
        // work type) — this overrides the earlier "ambiguous, leave unspecified" rule
        // for exactly these cases, per explicit business-owner confirmation.
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match('xaafad jiif iyo maalin'));
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match('Xafad'));
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match('Xafad diyaar ah'));
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match('xafad jday'));
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match('home cleaningcleaning'));

        // A null/blank job never reaches this matcher in practice (CategoryMatcher would
        // never have assigned Home Team without a real keyword match), but stays null
        // here rather than fabricating a work type from nothing.
        $this->assertNull(HomeCleaningWorkTypeMatcher::match(null));
        $this->assertNull(HomeCleaningWorkTypeMatcher::match(''));

        // REGRESSION: "day" must only match as a whole word — "diiday" ("refused") and
        // "aaday" ("went/left") both legitimately end in "day" but must NOT be treated as
        // the Maalin work type, and must not collide with a genuine "jiif" mention in the
        // same text to falsely trigger the both-mentioned ambiguity rule. Confirmed against
        // 2 real rows in the actual workbook that were wrongly flagged ambiguous by this bug.
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match('xafad jiif shaqo way diiday'));
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, HomeCleaningWorkTypeMatcher::match('Xafad Jiif  safar aaday'));
    }

    /**
     * CONFIRMED business structure: under Cooking, "Cunto & Nadaafad" is the explicitly
     * combined cooking+cleaning specialization — assigned only when the source text
     * explicitly names the cleaning combination. "Cook" is the safe default otherwise,
     * never a third, invented specialization.
     */
    public function test_cooking_specialization_matcher(): void
    {
        foreach (['Chef', 'cook', 'Cunto karin', 'cunto ku fiican', 'cunta karis'] as $job) {
            $this->assertSame(CookingSpecializationMatcher::COOK, CookingSpecializationMatcher::match($job), "Failed for: {$job}");
        }

        foreach (['Cunto karis nadaafad', 'cunto &nadafad', 'cunto iyo nadafad malin', 'cunta karis nadafad'] as $job) {
            $this->assertSame(CookingSpecializationMatcher::CUNTO_AND_NADAAFAD, CookingSpecializationMatcher::match($job), "Failed for: {$job}");
        }

        $this->assertNull(CookingSpecializationMatcher::match(null));
    }

    /**
     * CONFIRMED business rule: all four green shades (92D050 / 00B050 / 548135 /
     * 53CD93) mean exactly the same thing — "Wuu shaqeeyaa ama hore shaqo loo
     * geeyay" — and must be read identically. This isolates RegistrationColorReader
     * itself so each hex value is proven independently of the full pipeline.
     */
    public function test_registration_color_reader_recognizes_all_four_green_shades(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hr_color_fixture_').'.xlsx';

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);
            $registration = $spreadsheet->createSheet();
            $registration->setTitle('REGISTRATION');
            $registration->fromArray(['Date', 'Name'], null, 'A1');
            $registration->fromArray([null, 'Green Shade 92D050'], null, 'A2');
            $registration->fromArray([null, 'Green Shade 00B050'], null, 'A3');
            $registration->fromArray([null, 'Green Shade 548135'], null, 'A4');
            $registration->fromArray([null, 'Green Shade 53CD93'], null, 'A5');
            $registration->fromArray([null, 'Uncolored Person'], null, 'A6');
            $registration->fromArray([null, 'Other Color Person'], null, 'A7');

            $registration->getStyle('B2')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('92D050');
            $registration->getStyle('B3')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('00B050');
            $registration->getStyle('B4')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('548135');
            $registration->getStyle('B5')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('53CD93');
            // Row 6 deliberately left with no fill — CONFIRMED rule: no fill = guarantor needed.
            $registration->getStyle('B7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC000'); // some other color — CONFIRMED rule: guarantor needed.

            (new Xlsx($spreadsheet))->save($path);

            $colors = app(RegistrationColorReader::class)->read($path);

            foreach ([2 => '92D050', 3 => '00B050', 4 => '548135', 5 => '53CD93'] as $row => $shade) {
                $this->assertArrayHasKey($row, $colors, "Row {$row} ({$shade}) was not recorded at all");
                $this->assertTrue($colors[$row]['isGreen'], "Row {$row} ({$shade}) was not flagged green");
                $this->assertSame($shade, $colors[$row]['greenShade']);
                $this->assertFalse($colors[$row]['isRed']);
                $this->assertFalse($colors[$row]['isYellow']);
                $this->assertFalse($colors[$row]['isDamiinNeeded'], "Green row {$row} must not also be flagged guarantor-needed.");
            }

            // CONFIRMED rule: no fill at all = guarantor needed (never "no signal").
            $this->assertArrayHasKey(6, $colors);
            $this->assertTrue($colors[6]['isDamiinNeeded']);
            $this->assertFalse($colors[6]['isRed']);
            $this->assertFalse($colors[6]['isYellow']);
            $this->assertFalse($colors[6]['isGreen']);
            $this->assertSame('no-fill', $colors[6]['colorContext']);

            // CONFIRMED rule: every other color = guarantor needed.
            $this->assertArrayHasKey(7, $colors);
            $this->assertTrue($colors[7]['isDamiinNeeded']);
            $this->assertFalse($colors[7]['isRed']);
            $this->assertFalse($colors[7]['isYellow']);
            $this->assertFalse($colors[7]['isGreen']);
            $this->assertSame('other:FFC000', $colors[7]['colorContext']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * CONFIRMED business rule: "paid"/"piad"/"unpaid"/bare amounts are training-fee-status
     * noise, never a cancellation reason — inspection of the real workbook showed the
     * designated reason column is 97% this exact noise. A genuine value in the same
     * position IS preserved verbatim, never guessed at.
     */
    public function test_cancellation_reason_extractor_filters_known_noise_but_preserves_genuine_text(): void
    {
        foreach (['paid', 'Paid', 'PIAD', 'piad', 'unpaid', 'pending', 'n/a', 'N/A', 'none', '-', '', '  ', '1500', '1,500', null] as $noise) {
            $this->assertNull(CancellationReasonExtractor::extract($noise), 'Failed to treat as noise: '.var_export($noise, true));
        }

        $this->assertSame('left the country', CancellationReasonExtractor::extract('left the country'));
        $this->assertSame('mowlid', CancellationReasonExtractor::extract('mowlid'));
        $this->assertSame('mahad', CancellationReasonExtractor::extract(' mahad '));
    }

    public function test_resolved_person_never_carries_a_gender_field(): void
    {
        // Structural guarantee, not just behavioral: gender cannot be set because the
        // DTO a commit-mode would consume has no such property to infer into.
        $properties = array_map(
            fn ($p) => $p->getName(),
            (new ReflectionClass(ResolvedPerson::class))->getConstructor()->getParameters(),
        );

        $this->assertNotContains('gender', $properties);
    }

    /**
     * CONFIRMED business rule: Excel contains no guarantor/Damiin data for anyone —
     * "Other Contact"/"Other Contact Name" are the employee's own secondary/emergency
     * contact, never the guarantor. Structural guarantee, not just behavioral: there is
     * no "guarantorPhone"/"guarantorName" property at all for a commit-mode to fabricate
     * an EmployeeGuarantor from — only secondaryContactPhone/secondaryContactName exist.
     */
    public function test_resolved_person_never_carries_a_guarantor_record_field(): void
    {
        $properties = array_map(
            fn ($p) => $p->getName(),
            (new ReflectionClass(ResolvedPerson::class))->getConstructor()->getParameters(),
        );

        $this->assertNotContains('guarantorPhone', $properties);
        $this->assertNotContains('guarantorName', $properties);
        $this->assertContains('secondaryContactPhone', $properties);
        $this->assertContains('secondaryContactName', $properties);
        $this->assertContains('guarantorNeedFlag', $properties);
    }

    /**
     * CONFIRMED business decision: the "Waiting List" sheet's second block (a "NEW
     * WAITING LIST" section confirmed to start at row 92, with NO Date column) must be
     * parsed with its own corrected column layout and matched against REGISTRATION by
     * the CORRECTED name/phone — not treated as independent, standalone records.
     */
    public function test_waiting_list_second_block_parses_with_corrected_layout_and_matches_registration(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hr_second_block_fixture_').'.xlsx';

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $registration = $spreadsheet->createSheet();
            $registration->setTitle('REGISTRATION');
            $registration->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage', 'Experience', 'Other Contact', 'Other Contact Names', 'Other information', 'Training Fee'], null, 'A1');
            $registration->fromArray(['22/11/2018', 'Second Block Matched Person', '615900001', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A2');

            $waitingList = $spreadsheet->createSheet();
            $waitingList->setTitle('Waiting List');
            $waitingList->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Matial status', 'Live with', 'Reference', 'Ready'], null, 'A1');
            // Row 92 onward: the CONFIRMED second-block layout — Name,Phone,Job,Location,
            // Age,Marital,LivesWith,Reference,Stage,Experience,OtherContactPhone,
            // OtherContactName,OtherInfo — deliberately NO date column.
            $waitingList->fromArray(['Second Block Matched Person', '615900001', 'General cleaning', 'SomeLoc', '25', 'Single', 'Hooyo', 'Facebook', 'need training', 'none', null, null, null], null, 'A92');
            $waitingList->fromArray(['Second Block New Candidate', '615900002', 'General cleaning', 'OtherLoc', '30', 'Married', 'Hooyo', 'Facebook', 'need training', 'none', null, null, null], null, 'A93');

            (new Xlsx($spreadsheet))->save($path);

            $service = app(ExcelMigrationService::class);
            $loaded = $service->loadWorkbook($path);
            $colors = app(RegistrationColorReader::class)->read($path);
            $report = $service->analyze($loaded, ['General Cleaning', 'Cooking', 'Home Team'], $colors);

            $all = collect([...$report->readyToImport, ...$report->manualReview])->keyBy('fullName');

            // The second-block row's real name/phone were correctly parsed (not misread as
            // date/name) and correctly matched the REGISTRATION person by phone — ONE
            // Employee, not two, with REGISTRATION as master.
            $matched = $all->get('Second Block Matched Person');
            $this->assertNotNull($matched, 'Second-block person was not parsed/matched at all — likely still using the wrong column layout.');
            $this->assertContains('REGISTRATION', $matched->sourceSheets);
            $this->assertContains('Waiting List', $matched->sourceSheets);
            $this->assertSame('2018-11-22', $matched->applicationDate?->toDateString(), 'REGISTRATION date must remain the master application_date.');
            $this->assertSame(EmployeeStatus::Waiting, $matched->status);
            // No date column exists in this block, so there is nothing to source
            // waiting_since from for this specific person — null, never fabricated.
            $this->assertNull($matched->waitingSince);

            // A second-block person with NO REGISTRATION match is still a legitimate new
            // candidate — never discarded, never blocked from readiness by the missing date.
            $newCandidate = $all->get('Second Block New Candidate');
            $this->assertNotNull($newCandidate);
            $this->assertNotContains('REGISTRATION', $newCandidate->sourceSheets);
            $this->assertNull($newCandidate->applicationDate);
            $this->assertTrue($newCandidate->isReadyToImport);
            $this->assertSame(EmployeeStatus::Waiting, $newCandidate->status);

            // CONFIRMED: Waiting List people are NEVER need_training or guarantor-needed,
            // even though this fixture's Stage cell literally says "need training".
            $this->assertNotSame(EmployeePipelineStage::NeedTraining, $newCandidate->pipelineStage);
            $this->assertFalse($newCandidate->guarantorNeedFlag);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * CONFIRMED business decision: a REGISTRATION row whose own Date cell can't be
     * parsed at all gets its application_date inferred from the surrounding sequence,
     * end to end through the real pipeline — not just at the unit level.
     */
    public function test_registration_date_inference_integrates_with_the_full_pipeline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hr_date_inference_fixture_').'.xlsx';

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $registration = $spreadsheet->createSheet();
            $registration->setTitle('REGISTRATION');
            $registration->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A1');
            $registration->fromArray(['01/06/2020', 'Filler A', '615800001', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A2');
            $registration->fromArray(['01/06/2020', 'Filler B', '615800002', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A3');
            $registration->fromArray([null, 'Date Inference Target', '615800003', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A4');
            $registration->fromArray(['01/06/2020', 'Filler C', '615800004', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A5');
            $registration->fromArray(['01/06/2020', 'Filler D', '615800005', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A6');
            // CONFIRMED business decision: an application_date that genuinely cannot be
            // resolved (own value blank, neighbors disagree) is acceptable and is never a
            // manual-review blocker by itself — this person has no OTHER blocking issue.
            $registration->fromArray(['15/03/2021', 'Disagreeing Neighbor Before', '615800006', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A7');
            $registration->fromArray([null, 'Unresolvable Date Person', '615800007', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A8');
            $registration->fromArray(['20/09/2021', 'Disagreeing Neighbor After', '615800008', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A9');

            (new Xlsx($spreadsheet))->save($path);

            $service = app(ExcelMigrationService::class);
            $loaded = $service->loadWorkbook($path);
            $colors = app(RegistrationColorReader::class)->read($path);
            $report = $service->analyze($loaded, ['General Cleaning', 'Cooking', 'Home Team'], $colors);

            $all = collect([...$report->readyToImport, ...$report->manualReview])->keyBy('fullName');
            $target = $all->get('Date Inference Target');

            $this->assertNotNull($target);
            $this->assertSame('2020-06-01', $target->applicationDate?->toDateString());
            $this->assertTrue($target->applicationDateInferred);
            $this->assertStringContainsString('row 3', $target->applicationDateInferenceNote);
            $this->assertStringContainsString('row 5', $target->applicationDateInferenceNote);
            $this->assertSame(1, $report->registrationDateInferredCount);

            // CONFIRMED business decision (final): a genuinely unresolvable application_date
            // (own value blank, neighbors disagree: 2021-03-15 before vs 2021-09-20 after)
            // is acceptable and NEVER blocks readiness by itself.
            $unresolvable = $all->get('Unresolvable Date Person');
            $this->assertNotNull($unresolvable);
            $this->assertNull($unresolvable->applicationDate);
            $this->assertFalse($unresolvable->applicationDateInferred);
            $this->assertTrue($unresolvable->isReadyToImport, 'A null application_date must never block readiness by itself.');
            $this->assertEmpty($unresolvable->reviewReasons);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * CONFIRMED business decision: a Waiting List row with no valid phone may be
     * recovered via a conservative REGISTRATION name match — REGISTRATION stays master,
     * no duplicate Employee is created. Multiple plausible matches are NEVER
     * auto-resolved by name alone; Location is only a tie-breaker.
     */
    public function test_waiting_list_no_phone_recovered_via_registration_name_match(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hr_name_recovery_fixture_').'.xlsx';

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $registration = $spreadsheet->createSheet();
            $registration->setTitle('REGISTRATION');
            $registration->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A1');
            $registration->fromArray(['22/11/2018', 'Recoverable Person', '615800010', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A2');
            // Two DIFFERENT real people who happen to share the exact same name.
            $registration->fromArray(['22/11/2018', 'Duplicate Name Match A', '615800011', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A3');
            $registration->fromArray(['22/11/2018', 'Duplicate Name Match A', '615800012', 'Chef', 'Kaaraan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A4');

            $waitingList = $spreadsheet->createSheet();
            $waitingList->setTitle('Waiting List');
            $waitingList->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Matial status', 'Live with', 'Reference', 'Ready'], null, 'A1');
            // Unique name match, no phone — must be recovered, not left as a phantom person.
            $waitingList->fromArray(['25-11-2018', 'Recoverable Person', null, 'General cleaning', 'Hodan', '25', 'Garoob', 'Hooyo', 'Facebook', null], null, 'A2');
            // Same name as TWO different REGISTRATION people, no Location to disambiguate — must stay unresolved.
            $waitingList->fromArray(['25-11-2018', 'Duplicate Name Match A', null, 'General cleaning', null, '25', 'Garoob', 'Hooyo', 'Facebook', null], null, 'A3');
            // Same ambiguous name, but Location matches ONE of the two candidates exactly — tie-break resolves it.
            $waitingList->fromArray(['25-11-2018', 'Duplicate Name Match A', null, 'General cleaning', 'Kaaraan', '25', 'Garoob', 'Hooyo', 'Facebook', null], null, 'A4');

            (new Xlsx($spreadsheet))->save($path);

            $service = app(ExcelMigrationService::class);
            $loaded = $service->loadWorkbook($path);
            $colors = app(RegistrationColorReader::class)->read($path);
            $report = $service->analyze($loaded, ['General Cleaning', 'Cooking', 'Home Team'], $colors);

            $all = [...$report->readyToImport, ...$report->manualReview];
            $byPhone = collect($all)->keyBy('normalizedPhone');

            // Unique match: recovered onto the SAME Employee as the REGISTRATION person —
            // one person, not a phantom "no-phone" duplicate.
            $recovered = $byPhone->get('615800010');
            $this->assertNotNull($recovered, 'Recoverable Person was not merged into the REGISTRATION identity.');
            $this->assertContains('REGISTRATION', $recovered->sourceSheets);
            $this->assertContains('Waiting List', $recovered->sourceSheets);
            $this->assertSame(1, count(array_filter($all, fn ($p) => $p->fullName === 'Recoverable Person')), 'Recoverable Person must resolve to exactly ONE person, never a duplicate.');
            // 2, not 1: the unique "Recoverable Person" match AND the location-tie-broken
            // "Duplicate Name Match A" (row 4) both count as recoveries.
            $this->assertSame(2, $report->waitingPhoneRecoveredViaNameMatchCount);

            // Ambiguous (no location to disambiguate): stays as its own unresolved
            // no-phone entry — never guessed, never silently merged into either candidate.
            $ambiguousPerson = collect($all)->first(fn ($p) => str_starts_with($p->normalizedPhone, 'no-phone:') && $p->fullName === 'Duplicate Name Match A');
            $this->assertNotNull($ambiguousPerson, 'Ambiguous no-location row should remain its own unresolved entry.');
            // CONFIRMED: this is the ONE genuine identity-conflict case that still blocks
            // readiness — every other missing-phone case no longer does.
            $this->assertFalse($ambiguousPerson->isReadyToImport, 'A genuinely ambiguous name match must remain the one case that still blocks readiness.');
            $this->assertCount(1, $report->waitingNameMatchAmbiguous);
            $this->assertCount(2, $report->waitingNameMatchAmbiguous[0]['candidates']);

            // Same ambiguous name, but Location narrows it to exactly one REGISTRATION
            // candidate (615800012, Kaaraan) — tie-break resolves it, no duplicate created.
            $tieBroken = $byPhone->get('615800012');
            $this->assertNotNull($tieBroken);
            $this->assertContains('Waiting List', $tieBroken->sourceSheets);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * CONFIRMED business decision: this is now the SAME principle already approved for
     * new Waiting List candidates — a new wiilasha candidate (no REGISTRATION match)
     * never requires application_date; a null date alone never blocks readiness.
     */
    public function test_wiilasha_new_candidate_application_date_may_be_null(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hr_wiilasha_date_fixture_').'.xlsx';

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $registration = $spreadsheet->createSheet();
            $registration->setTitle('REGISTRATION');
            $registration->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A1');

            $wiilasha = $spreadsheet->createSheet();
            $wiilasha->setTitle('wiilasha');
            $wiilasha->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Matial Status', 'Live with', 'Reference', null, 'Experience', 'Other Contact'], null, 'A1');
            $wiilasha->fromArray([null, 'Wiilasha New No Date Person', '615800020', 'General cleaning', 'Somewhere', '25', 'Single', 'Reerkiisa', 'Indhey', null, null, null], null, 'A2');

            (new Xlsx($spreadsheet))->save($path);

            $service = app(ExcelMigrationService::class);
            $loaded = $service->loadWorkbook($path);
            $colors = app(RegistrationColorReader::class)->read($path);
            $report = $service->analyze($loaded, ['General Cleaning', 'Cooking', 'Home Team'], $colors);

            $all = collect([...$report->readyToImport, ...$report->manualReview])->keyBy('fullName');
            $person = $all->get('Wiilasha New No Date Person');

            $this->assertNotNull($person);
            $this->assertNotContains('REGISTRATION', $person->sourceSheets);
            $this->assertNull($person->applicationDate);
            $this->assertTrue($person->isReadyToImport, 'A new wiilasha candidate must never be blocked solely by a missing application_date.');
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * CONFIRMED business decision: the exact same conservative phone-recovery mechanism
     * already proven for Waiting List now also applies to Home Cleaning and wiilasha —
     * same standard (name primary, Location tie-breaker only, never guessed when
     * ambiguous, never a duplicate Employee).
     */
    public function test_home_cleaning_and_wiilasha_no_phone_recovered_via_registration_name_match(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hr_hc_wiilasha_recovery_fixture_').'.xlsx';

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $registration = $spreadsheet->createSheet();
            $registration->setTitle('REGISTRATION');
            $registration->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A1');
            $registration->fromArray(['22/11/2018', 'HC Recoverable Person', '615800030', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A2');
            $registration->fromArray(['22/11/2018', 'Wiilasha Recoverable Person', '615800031', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A3');
            // Two different real people sharing the exact same name — forces ambiguity.
            $registration->fromArray(['22/11/2018', 'HC Duplicate Name', '615800032', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A4');
            $registration->fromArray(['22/11/2018', 'HC Duplicate Name', '615800033', 'Chef', 'Kaaraan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A5');

            $homeCleaning = $spreadsheet->createSheet();
            $homeCleaning->setTitle('Home Cleaning');
            $homeCleaning->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A1');
            $homeCleaning->fromArray(['25/11/2018', 'HC Recoverable Person', null, 'Xafad jiif', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A2');
            // Ambiguous: no location given, matches BOTH "HC Duplicate Name" candidates.
            $homeCleaning->fromArray(['25/11/2018', 'HC Duplicate Name', null, 'Xafad jiif', null, '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A3');

            $wiilasha = $spreadsheet->createSheet();
            $wiilasha->setTitle('wiilasha');
            $wiilasha->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Matial Status', 'Live with', 'Reference', null, 'Experience', 'Other Contact'], null, 'A1');
            $wiilasha->fromArray(['13/1/2019', 'Wiilasha Recoverable Person', null, 'Machines', 'Somewhere', '25', 'Single', 'Reerkiisa', 'Indhey', null, null, null], null, 'A2');

            (new Xlsx($spreadsheet))->save($path);

            $service = app(ExcelMigrationService::class);
            $loaded = $service->loadWorkbook($path);
            $colors = app(RegistrationColorReader::class)->read($path);
            $report = $service->analyze($loaded, ['General Cleaning', 'Cooking', 'Home Team'], $colors);

            $all = [...$report->readyToImport, ...$report->manualReview];
            $byPhone = collect($all)->keyBy('normalizedPhone');

            // Home Cleaning unique match: merged into the SAME Employee, no duplicate.
            $hcRecovered = $byPhone->get('615800030');
            $this->assertNotNull($hcRecovered, 'HC Recoverable Person was not merged into the REGISTRATION identity.');
            $this->assertContains('REGISTRATION', $hcRecovered->sourceSheets);
            $this->assertContains('Home Cleaning', $hcRecovered->sourceSheets);
            $this->assertSame(1, count(array_filter($all, fn ($p) => $p->fullName === 'HC Recoverable Person')));

            // wiilasha unique match: merged into the SAME Employee, no duplicate.
            $wiilashaRecovered = $byPhone->get('615800031');
            $this->assertNotNull($wiilashaRecovered, 'Wiilasha Recoverable Person was not merged into the REGISTRATION identity.');
            $this->assertContains('REGISTRATION', $wiilashaRecovered->sourceSheets);
            $this->assertContains('wiilasha', $wiilashaRecovered->sourceSheets);
            $this->assertSame(1, count(array_filter($all, fn ($p) => $p->fullName === 'Wiilasha Recoverable Person')));

            $this->assertSame(2, $report->waitingPhoneRecoveredViaNameMatchCount);

            // Home Cleaning ambiguous (no location): never guessed, stays its own entry,
            // and remains the one genuine identity conflict that still blocks readiness.
            $hcAmbiguous = collect($all)->first(fn ($p) => str_starts_with($p->normalizedPhone, 'no-phone:') && $p->fullName === 'HC Duplicate Name');
            $this->assertNotNull($hcAmbiguous, 'Ambiguous Home Cleaning no-location row should remain its own unresolved entry.');
            $this->assertFalse($hcAmbiguous->isReadyToImport);
            $this->assertCount(1, $report->waitingNameMatchAmbiguous);
            $this->assertCount(2, $report->waitingNameMatchAmbiguous[0]['candidates']);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    /**
     * CONFIRMED business decision: Supervisor matching applies regardless of source
     * sheet, and any remaining unmatched Job value defaults to General Cleaning (marked
     * as a low-confidence catch-all, never a manual-review blocker) rather than staying
     * null — the raw Job text is always preserved either way.
     */
    public function test_category_supervisor_and_general_cleaning_catchall_integrate_with_the_full_pipeline(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'hr_category_catchall_fixture_').'.xlsx';

        try {
            $spreadsheet = new Spreadsheet;
            $spreadsheet->removeSheetByIndex(0);

            $registration = $spreadsheet->createSheet();
            $registration->setTitle('REGISTRATION');
            $registration->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A1');
            // "Supervisor" applies regardless of sheet — here it's a plain REGISTRATION row,
            // not the Supervisors sheet, and not the is_supervisor flag.
            $registration->fromArray(['22/11/2018', 'Registration Supervisor Person', '615800040', 'Supervisor', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A2');
            // Totally unrelated Job text — must default to General Cleaning, never left
            // unmatched, never blocking readiness.
            $registration->fromArray(['22/11/2018', 'Catchall Category Person', '615800041', 'Totally Unrelated Word', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A3');

            (new Xlsx($spreadsheet))->save($path);

            $service = app(ExcelMigrationService::class);
            $loaded = $service->loadWorkbook($path);
            $colors = app(RegistrationColorReader::class)->read($path);
            // Mirrors what the command itself does — model "Supervisor" as existing for
            // this analysis, without any database write.
            $report = $service->analyze($loaded, ['General Cleaning', 'Cooking', 'Home Team', 'Supervisor'], $colors);

            $all = collect([...$report->readyToImport, ...$report->manualReview])->keyBy('fullName');

            $supervisorPerson = $all->get('Registration Supervisor Person');
            $this->assertNotNull($supervisorPerson);
            $this->assertSame('Supervisor', $supervisorPerson->matchedCategory);
            $this->assertTrue($supervisorPerson->matchedCategoryIsConfident);
            $this->assertTrue($supervisorPerson->isReadyToImport);

            $catchallPerson = $all->get('Catchall Category Person');
            $this->assertNotNull($catchallPerson);
            $this->assertSame('General Cleaning', $catchallPerson->matchedCategory);
            $this->assertFalse($catchallPerson->matchedCategoryIsConfident, 'Catch-all assignment must be distinguishable from a real keyword match.');
            $this->assertSame('Totally Unrelated Word', $catchallPerson->rawJobTitle, 'Original raw Job text must stay preserved for audit purposes.');
            $this->assertTrue($catchallPerson->isReadyToImport, 'An unmatched category must never block readiness any more.');

            $this->assertSame(1, $report->categoryFallbackToGeneralCleaningCount);
            $this->assertArrayHasKey('totally unrelated word', $report->unmatchedCategoryValues);
        } finally {
            if (is_file($path)) {
                unlink($path);
            }
        }
    }

    public function test_dry_run_report_classifies_every_scenario_correctly(): void
    {
        // employee_categories.up() migration already seeds General Cleaning/Cooking/
        // Waiter/Barista/Home Team — no need to create them here.
        $service = app(ExcelMigrationService::class);
        $spreadsheet = $service->loadWorkbook($this->fixturePath);
        $colorReader = app(RegistrationColorReader::class);
        $registrationColors = $colorReader->read($this->fixturePath);
        $report = $service->analyze($spreadsheet, ['General Cleaning', 'Cooking', 'Home Team'], $registrationColors);

        $ready = collect($report->readyToImport)->keyBy('fullName');
        $review = collect($report->manualReview)->keyBy('fullName');

        // Same person across two sheets (REGISTRATION + Home Cleaning, same phone,
        // compatible name) merges into ONE record, not two.
        $duplicateName = 'Zamzam Hassan Osman';
        $this->assertTrue($ready->has($duplicateName) || $review->has($duplicateName));
        $merged = $ready->get($duplicateName) ?? $review->get($duplicateName);
        $this->assertCount(2, $merged->sourceSheets);
        // REGISTRATION (master source) provides "Nadaafad" for this person, which correctly
        // wins over Home Cleaning's "Xaafad" — same master-source precedence as the date rule.
        // The Xaafad-always-means-Home-Team rule has its own dedicated unit test above.
        $this->assertSame('General Cleaning', $merged->matchedCategory);

        // Cancelled person from the CANCELED sheet is present (not deleted/excluded) and inactive.
        $cancelled = $ready->get('Cancelled Person') ?? $review->get('Cancelled Person');
        $this->assertNotNull($cancelled);
        $this->assertTrue($cancelled->isCancellation);
        $this->assertSame(EmployeeStatus::Inactive, $cancelled->status);
        $this->assertContains('CANCELED sheet (row 1)', $cancelled->cancellationSources);

        // RED-highlighted REGISTRATION person (NOT on the CANCELED sheet at all) is ALSO
        // cancelled — confirmed business rule: red fill alone is authoritative, the
        // CANCELED sheet is just one of two sources that both count.
        $redOnly = $ready->get('Red Only Person') ?? $review->get('Red Only Person');
        $this->assertNotNull($redOnly);
        $this->assertTrue($redOnly->isCancellation);
        $this->assertSame(EmployeeStatus::Inactive, $redOnly->status);
        $this->assertContains('RED fill in REGISTRATION (row 8)', $redOnly->cancellationSources);
        // A CANCELED-sheet person with NO valid phone number must still surface as
        // cancelled — never silently dropped into missingPhoneCount unreviewed. CONFIRMED
        // business decision (final): a missing phone is never a manual-review blocker by
        // itself, so this person is now Ready to Import — isCancellation/status are still
        // correctly set, and the missing phone is simply preserved as null, never fabricated.
        $noPhoneCancelled = $ready->get('Cancelled No Phone Person') ?? $review->get('Cancelled No Phone Person');
        $this->assertNotNull($noPhoneCancelled);
        $this->assertTrue($noPhoneCancelled->isCancellation);
        $this->assertSame(EmployeeStatus::Inactive, $noPhoneCancelled->status);
        $this->assertTrue($noPhoneCancelled->isReadyToImport, 'A missing phone alone must never block readiness any more.');
        $this->assertContains('CANCELED sheet (row 2)', $noPhoneCancelled->cancellationSources);

        // A person present in BOTH sources (red REGISTRATION row + CANCELED-sheet row,
        // same phone) is ONE Employee, not two — with both source references preserved.
        $bothSources = $ready->get('Red And Canceled Person') ?? $review->get('Red And Canceled Person');
        $this->assertNotNull($bothSources);
        $this->assertTrue($bothSources->isCancellation);
        $this->assertContains('REGISTRATION', $bothSources->sourceSheets);
        $this->assertContains('CANCELED', $bothSources->sourceSheets);
        $this->assertContains('RED fill in REGISTRATION (row 12)', $bothSources->cancellationSources);
        $this->assertContains('CANCELED sheet (row 3)', $bothSources->cancellationSources);

        // Final cancelled population = union of both sources, deduplicated — 7, not 4:
        // Cancelled Person + Red Only Person + Cancelled No Phone Person +
        // Red And Canceled Person (counted once) + Registered Then Canceled Person +
        // Cancelled Paid Noise Person + Cancelled Real Reason Person.
        $this->assertSame(7, $report->cancelledCount);
        $this->assertSame(2, $report->registrationRedRowCount);
        $this->assertSame(6, $report->canceledSheetRowCount);
        $this->assertSame(1, $report->redAndCanceledOverlapCount);

        // Explicit person-level reconciliation must independently agree with cancelledCount.
        $reconciliation = $report->cancellationReconciliation;
        $this->assertNotNull($reconciliation);
        $this->assertSame(2, $reconciliation->redRowsTotal);
        $this->assertSame(2, $reconciliation->redClassA); // Red Only Person + Red And Canceled Person's red row
        $this->assertSame(6, $reconciliation->canceledRowsTotal);
        $this->assertSame(4, $reconciliation->canceledClassA); // Cancelled Person + Registered Then Canceled Person + the 2 new cancellation-reason rows
        $this->assertSame(1, $reconciliation->canceledClassB); // Red And Canceled Person's CANCELED row (same person as its red row)
        $this->assertSame(1, $reconciliation->canceledClassE); // Cancelled No Phone Person
        $this->assertSame(1, $reconciliation->overlapPersons); // Red And Canceled Person
        $this->assertSame(7, $reconciliation->finalUniqueCancelledCount());
        $this->assertSame($report->cancelledCount, $reconciliation->finalUniqueCancelledCount());

        // CONFIRMED business rule: "paid"/"piad"/amounts in the reason-position column are
        // fee-status noise, NEVER a cancellation reason — never invented, never misrepresented.
        $paidNoisePerson = $ready->get('Cancelled Paid Noise Person') ?? $review->get('Cancelled Paid Noise Person');
        $this->assertNotNull($paidNoisePerson);
        $this->assertNull($paidNoisePerson->cancellationReason);
        $this->assertStringContainsString('paid', (string) $paidNoisePerson->cancellationSourceContext);

        // A genuine, non-noise value in that same field IS preserved verbatim as the reason.
        $realReasonPerson = $ready->get('Cancelled Real Reason Person') ?? $review->get('Cancelled Real Reason Person');
        $this->assertNotNull($realReasonPerson);
        $this->assertSame('left the country', $realReasonPerson->cancellationReason);

        // CONFIRMED business rule: Stage values (Ready/Stop/Need Training/etc.) are never
        // treated as a cancellation reason — Red Only Person's Stage is "need training" and
        // REGISTRATION isn't a reason-designated sheet, so no reason should be inferred.
        $redOnlyForReason = $ready->get('Red Only Person') ?? $review->get('Red Only Person');
        $this->assertNotNull($redOnlyForReason);
        $this->assertNull($redOnlyForReason->cancellationReason);

        // Regression test for a real bug caught before shipping: a person with an ORDINARY
        // (non-red, non-highlighted) REGISTRATION row plus a CANCELED-sheet row must be
        // classified via the CANCELED row as "A: valid unique cancelled person" — NOT as
        // a "B: duplicate" just because an unrelated, non-cancelled row for them happens to
        // sort earlier in the group. The ordinary REGISTRATION row itself is not tallied at
        // all (it isn't a cancellation source), so it must never be mistaken for the "first"
        // cancellation signal that the CANCELED row then wrongly "duplicates".
        $registeredThenCanceled = $ready->get('Registered Then Canceled Person') ?? $review->get('Registered Then Canceled Person');
        $this->assertNotNull($registeredThenCanceled);
        $this->assertTrue($registeredThenCanceled->isCancellation);
        $this->assertContains('CANCELED sheet (row 4)', $registeredThenCanceled->cancellationSources);

        // Yellow-highlighted person: preserved as "already brought a guarantor" CONTEXT,
        // never forces pipeline_stage=damiin_needed and never blocks readiness by itself.
        // guarantor_need must be NO. CONFIRMED business rule: Excel has NO guarantor data
        // at all — not even for Yellow people — so NO guarantor record is ever fabricated,
        // regardless of what "Other Contact" happens to contain.
        $yellowPerson = $ready->get('Yellow Person') ?? $review->get('Yellow Person');
        $this->assertNotNull($yellowPerson);
        $this->assertTrue($yellowPerson->isYellowFlagged);
        $this->assertNotSame(EmployeePipelineStage::DamiinNeeded, $yellowPerson->pipelineStage);
        $this->assertStringContainsString('Damiin ayuu keensaday', (string) $yellowPerson->otherInfo);
        $this->assertFalse($yellowPerson->guarantorNeedFlag);
        $this->assertContains('Source indicates a guarantor was already provided ("Damiin ayuu keensaday"), but Excel contains no actual guarantor data — add and verify via Employee Profile → Guarantor/Damiin → Add Guarantor.', $yellowPerson->profileIncompleteReasons);
        $this->assertSame(2, $report->yellowRowCount);

        // CONFIRMED business rule: "Other Contact"/"Other Contact Name" are the employee's
        // OWN secondary/emergency contact — a second number to reach them by — NEVER the
        // guarantor. Even though this Yellow person has real, reliable Other Contact data,
        // it becomes secondaryContactPhone/Name, NOT a guarantor record, and profile
        // completeness must still say the guarantor record is missing regardless.
        $yellowWithSecondaryContact = $ready->get('Yellow With Secondary Contact Person') ?? $review->get('Yellow With Secondary Contact Person');
        $this->assertNotNull($yellowWithSecondaryContact);
        $this->assertTrue($yellowWithSecondaryContact->isYellowFlagged);
        $this->assertSame('615333444', $yellowWithSecondaryContact->secondaryContactPhone);
        $this->assertSame('Secondary Contact Real Name', $yellowWithSecondaryContact->secondaryContactName);
        $this->assertContains('Source indicates a guarantor was already provided ("Damiin ayuu keensaday"), but Excel contains no actual guarantor data — add and verify via Employee Profile → Guarantor/Damiin → Add Guarantor.', $yellowWithSecondaryContact->profileIncompleteReasons);

        // Unreliable Other Contact values (garbage phone, non-name text) are preserved as raw
        // context but never presented as trustworthy secondary contact info.
        $unreliableSecondaryContact = $ready->get('Unreliable Secondary Contact Person') ?? $review->get('Unreliable Secondary Contact Person');
        $this->assertNotNull($unreliableSecondaryContact);
        $this->assertNull($unreliableSecondaryContact->secondaryContactPhone);
        $this->assertNull($unreliableSecondaryContact->secondaryContactName);
        $this->assertStringContainsString('n/a', (string) $unreliableSecondaryContact->otherInfo);

        // CONFIRMED FINAL color rule: any color other than red/yellow/green means guarantor
        // needed — and the signal survives alongside the person's own (unrelated) pipeline
        // stage, never overwriting it.
        $otherColorPerson = $ready->get('Other Color Person') ?? $review->get('Other Color Person');
        $this->assertNotNull($otherColorPerson);
        $this->assertTrue($otherColorPerson->guarantorNeedFlag);
        $this->assertSame(EmployeePipelineStage::NeedTraining, $otherColorPerson->pipelineStage);

        // CONFIRMED override: green means NO guarantor needed AND no training needed, even
        // when a real "guarantor needed" signal exists (NEED DAMIIN sheet membership here) and
        // even when the Stage cell says "need training" — original Stage text stays preserved
        // via historyNote, only the DERIVED current flags are suppressed.
        $greenOverride = $ready->get('Green Damiin Override Person') ?? $review->get('Green Damiin Override Person');
        $this->assertNotNull($greenOverride);
        $this->assertTrue($greenOverride->isGreenFlagged);
        $this->assertFalse($greenOverride->guarantorNeedFlag);
        $this->assertNull($greenOverride->pipelineStage);
        $this->assertStringContainsString('need training', mb_strtolower($greenOverride->historyNote));
        $this->assertSame(1, $report->damiinOverriddenByGreenCount);
        $this->assertGreaterThanOrEqual(1, $report->trainingOverriddenByGreenCount);

        // CONFIRMED override: EVERY Waiting List person means NO guarantor needed and NO
        // training needed, even when NEED DAMIIN sheet membership also applies to them.
        $waitingOverride = $ready->get('Waiting Damiin Override Person') ?? $review->get('Waiting Damiin Override Person');
        $this->assertNotNull($waitingOverride);
        $this->assertSame(EmployeeStatus::Waiting, $waitingOverride->status);
        $this->assertFalse($waitingOverride->guarantorNeedFlag);
        $this->assertNotSame(EmployeePipelineStage::NeedTraining, $waitingOverride->pipelineStage);
        // >=1 rather than an exact count: "no fill" is now a real signal by default, so any
        // OTHER uncolored REGISTRATION row that also ends up matched to Waiting (e.g. "Waiting
        // Matched Person" above) legitimately adds to this same counter — the important,
        // precise proof is the per-person assertion above, not the aggregate total.
        $this->assertGreaterThanOrEqual(1, $report->damiinOverriddenByWaitingCount);

        // REGRESSION TEST for the core bug this rework fixes: guarantor-need must NOT
        // disappear just because REGISTRATION's own Stage ("need training") ranks equally
        // with the NEED DAMIIN sheet's Applicant-rank resolution — both must survive together,
        // completely independent of each other.
        $independentDamiin = $ready->get('Damiin Independent Stage Person') ?? $review->get('Damiin Independent Stage Person');
        $this->assertNotNull($independentDamiin);
        $this->assertTrue($independentDamiin->guarantorNeedFlag);
        $this->assertSame(EmployeePipelineStage::NeedTraining, $independentDamiin->pipelineStage);

        // The pre-existing NEED-DAMIIN-only fixture person (no REGISTRATION match at all)
        // also keeps guarantor_need = YES. The sheet's Other Contact data is a secondary
        // contact, NOT a guarantor record — need=YES and a captured secondary contact are
        // unrelated, non-contradictory facts about this person.
        $damiinOnly = $ready->get('Damiin Needed Person') ?? $review->get('Damiin Needed Person');
        $this->assertNotNull($damiinOnly);
        $this->assertTrue($damiinOnly->guarantorNeedFlag);
        $this->assertNull($damiinOnly->pipelineStage);
        $this->assertSame(EmployeeStatus::Applicant, $damiinOnly->status);
        $this->assertSame('615795314', $damiinOnly->secondaryContactPhone);
        $this->assertSame('walashayd Naima', $damiinOnly->secondaryContactName);
        $this->assertContains('Guarantor needed per source; no guarantor record exists yet — add via Employee Profile → Guarantor/Damiin → Add Guarantor.', $damiinOnly->profileIncompleteReasons);

        // Green-highlighted person: preserved as "worked/sent to work" CONTEXT only —
        // never automatically promoted to Active status. All FOUR confirmed green
        // shades must be treated identically — one person per shade, all asserted
        // the same way, so no single shade can silently diverge from the others.
        foreach ([
            'Green Person' => '92D050',
            'Green Person B' => '00B050',
            'Green Person C' => '548135',
            'Green Person D' => '53CD93',
        ] as $name => $shade) {
            $greenPerson = $ready->get($name) ?? $review->get($name);
            $this->assertNotNull($greenPerson, "Missing resolved person for shade {$shade} ({$name})");
            $this->assertTrue($greenPerson->isGreenFlagged, "{$name} ({$shade}) was not flagged green");
            $this->assertNotSame(EmployeeStatus::Active, $greenPerson->status, "{$name} ({$shade}) was auto-promoted to Active");
            $this->assertStringContainsString('Wuu shaqeeyaa', (string) $greenPerson->otherInfo, "{$name} ({$shade}) missing green context text");
        }

        // 5, not 4: the 4 dedicated shade-test people above, plus "Green Damiin Override
        // Person" (also 92D050, used above to test the override rule) — same shade, counted
        // as a genuinely separate green row.
        $this->assertSame(5, $report->greenTotalRowCount);
        $this->assertSame([
            '92D050' => 2,
            '00B050' => 1,
            '548135' => 1,
            '53CD93' => 1,
        ], $report->greenShadeCounts);

        // wiilasha is a shortcut, not a second registration: a wiilasha row matching
        // REGISTRATION by phone is the SAME person (one Employee, REGISTRATION's date wins).
        $wiilashaMatched = $ready->get('Wiilasha Matched Person') ?? $review->get('Wiilasha Matched Person');
        $this->assertNotNull($wiilashaMatched);
        $this->assertContains('REGISTRATION', $wiilashaMatched->sourceSheets);
        $this->assertContains('wiilasha', $wiilashaMatched->sourceSheets);
        $this->assertSame('2018-11-22', $wiilashaMatched->applicationDate?->toDateString()); // REGISTRATION's date wins
        $this->assertSame(1, $report->wiilashaMatchedCount);

        // A wiilasha person NOT found in REGISTRATION is a legitimate new candidate —
        // never discarded, never treated as invalid.
        $wiilashaNew = $ready->get('Wiilasha New Candidate') ?? $review->get('Wiilasha New Candidate');
        $this->assertNotNull($wiilashaNew);
        $this->assertSame(['wiilasha'], $wiilashaNew->sourceSheets);
        $this->assertSame(1, $report->wiilashaNewCandidateCount);

        // Waiting List matched to REGISTRATION: registration_date recovered from REGISTRATION,
        // waiting_since recovered separately from Waiting List's own date — never conflated.
        $waitingMatched = $ready->get('Waiting Matched Person') ?? $review->get('Waiting Matched Person');
        $this->assertNotNull($waitingMatched);
        $this->assertSame(EmployeeStatus::Waiting, $waitingMatched->status);
        $this->assertSame('2018-11-22', $waitingMatched->applicationDate?->toDateString());
        $this->assertSame('2018-11-25', $waitingMatched->waitingSince?->toDateString());
        // 2, not 1: "Waiting Matched Person" + "Waiting Damiin Override Person" (also matched,
        // used above to test the Waiting-overrides-guarantor-need rule).
        $this->assertSame(2, $report->waitingListMatchedCount);
        $this->assertGreaterThanOrEqual(1, $report->registrationDateRecoveredForWaitingCount);
        $this->assertGreaterThanOrEqual(1, $report->waitingDateRecoveredCount);

        // Waiting-list-only person (not in REGISTRATION) is still present, still waiting —
        // not dropped just because there's no prior registration record.
        $waiting = $ready->get('Waiting Person') ?? $review->get('Waiting Person');
        $this->assertNotNull($waiting);
        $this->assertSame(EmployeeStatus::Waiting, $waiting->status);
        // 2, not 1: "Waiting Person" (Waiting List, unmatched) + "No Date Person"
        // (NEW WAITING LIST2026, also unmatched — that sheet is part of the waiting family too).
        $this->assertSame(2, $report->waitingListUnmatchedCount);

        // Phone conflict: same phone, incompatible names — reported, never merged.
        $this->assertCount(1, $report->phoneConflicts);
        $this->assertSame('619999999', $report->phoneConflicts[0]->normalizedPhone);

        // Ambiguous Training Fee value is preserved raw, never guessed into an amount.
        $feePerson = $ready->get('Fee Ambiguous Person') ?? $review->get('Fee Ambiguous Person');
        $this->assertNotNull($feePerson);
        $this->assertNull($feePerson->trainingFeeStatus);
        $this->assertSame('0.95', $feePerson->trainingFeeRawUnmapped);

        // "paid" is the only Training Fee value ever mapped to a status.
        $paidPerson = $ready->get('Fee Paid Person') ?? $review->get('Fee Paid Person');
        $this->assertNotNull($paidPerson);
        $this->assertSame('paid', $paidPerson->trainingFeeStatus);

        // Row with no Date column at all (NEW WAITING LIST2026 shape) never gets a
        // fabricated date — and per the CONFIRMED decision, a genuinely new (unmatched)
        // Waiting-family candidate is NOT blocked from readiness just because
        // application_date is null; it simply stays null.
        $noDatePerson = $ready->get('No Date Person');
        $this->assertNotNull($noDatePerson);
        $this->assertNull($noDatePerson->applicationDate);
        $this->assertTrue($noDatePerson->isReadyToImport);
        $this->assertSame(EmployeeStatus::Waiting, $noDatePerson->status);

        // Unrecognized stage text is never forced into a status — and CONFIRMED business
        // decision (final): an unresolved Stage is never a manual-review blocker by
        // itself either, so this person is now Ready to Import with status simply null.
        $unknownStage = $ready->get('Unknown Stage Person') ?? $review->get('Unknown Stage Person');
        $this->assertNotNull($unknownStage);
        $this->assertNull($unknownStage->status);
        $this->assertTrue($unknownStage->isReadyToImport, 'An unresolved Stage alone must never block readiness any more.');

        // Job Orders and Payroll are never parsed into people at all.
        $this->assertFalse($ready->has('Job Orders Customer'));
        $this->assertFalse($review->has('Job Orders Customer'));
        $this->assertFalse($ready->has('Payroll Only Person'));
        $this->assertFalse($review->has('Payroll Only Person'));
        $this->assertGreaterThan(0, $report->excludedJobOrdersRowCount);
        $this->assertGreaterThan(0, $report->excludedPayrollRowCount);

        // MOGADISHU HOSPITAL never creates an Employee candidate — only a match report.
        $this->assertFalse($ready->has('Hospital Roster Name'));
        $this->assertFalse($review->has('Hospital Roster Name'));
        $this->assertGreaterThan(0, $report->mogadishuHospitalRowCount);

        // Student Practical training is always routed to manual review, never ready.
        $this->assertTrue($review->has('Student Practical Person'));
        $this->assertFalse($ready->has('Student Practical Person'));
        $this->assertSame(1, $report->studentPracticalRowCount);

        // CONFIRMED business structure: Home Cleaning Work Type is structured/searchable,
        // never just free text — "Xafad jiif" and "Xafad malin" both land under the Home
        // Team category with their own distinct, filterable work-type value.
        $jiifPerson = $ready->get('Home Cleaning Jiif Person') ?? $review->get('Home Cleaning Jiif Person');
        $this->assertNotNull($jiifPerson);
        $this->assertSame('Home Team', $jiifPerson->matchedCategory);
        $this->assertSame(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, $jiifPerson->categorySpecialization);

        $maalinPerson = $ready->get('Home Cleaning Maalin Person') ?? $review->get('Home Cleaning Maalin Person');
        $this->assertNotNull($maalinPerson);
        $this->assertSame('Home Team', $maalinPerson->matchedCategory);
        $this->assertSame(HomeCleaningWorkTypeMatcher::PART_TIME_MAALIN, $maalinPerson->categorySpecialization);

        $this->assertArrayHasKey(HomeCleaningWorkTypeMatcher::FULL_TIME_JIIF, $report->homeCleaningWorkTypeCounts);
        $this->assertArrayHasKey(HomeCleaningWorkTypeMatcher::PART_TIME_MAALIN, $report->homeCleaningWorkTypeCounts);

        // CONFIRMED business structure: Cooking specialization — "Chef" is plain "Cook";
        // an explicit cleaning combination ("nadaafad") makes it "Cunto & Nadaafad" —
        // never a third, invented specialization, never guessed the other way.
        $cookPerson = $ready->get('Cooking Cook Person') ?? $review->get('Cooking Cook Person');
        $this->assertNotNull($cookPerson);
        $this->assertSame('Cooking', $cookPerson->matchedCategory);
        $this->assertSame(CookingSpecializationMatcher::COOK, $cookPerson->categorySpecialization);

        $combinedPerson = $ready->get('Cooking Combined Person') ?? $review->get('Cooking Combined Person');
        $this->assertNotNull($combinedPerson);
        $this->assertSame('Cooking', $combinedPerson->matchedCategory);
        $this->assertSame(CookingSpecializationMatcher::CUNTO_AND_NADAAFAD, $combinedPerson->categorySpecialization);

        $this->assertSame([CookingSpecializationMatcher::COOK, CookingSpecializationMatcher::CUNTO_AND_NADAAFAD], array_keys($report->cookingSpecializationCounts));

        // Category specialization is null for every other category — never invented where
        // no specialization concept exists. "$merged" (asserted General Cleaning above)
        // has no specialization concept defined for its category at all.
        $this->assertNull($merged->categorySpecialization);

        // Generic per-sheet summary: REGISTRATION shows raw rows only (it's the master
        // source, not "matched to" anything); Home Cleaning shows real rows and at least
        // the 2 new dedicated work-type test people.
        $this->assertArrayHasKey('REGISTRATION', $report->sheetSummary);
        $this->assertGreaterThan(0, $report->sheetSummary['REGISTRATION']['rows']);
        $this->assertArrayHasKey('Home Cleaning', $report->sheetSummary);
        $this->assertGreaterThanOrEqual(4, $report->sheetSummary['Home Cleaning']['rows']);
        $this->assertArrayHasKey('CANCELED', $report->sheetSummary);
        $this->assertSame(6, $report->sheetSummary['CANCELED']['rows']);

        // Non-employee-source sheet presence is explicit, never ambiguous with "empty".
        $this->assertTrue($report->otherSheetPresence['Payroll']);
        $this->assertTrue($report->otherSheetPresence['Job Orders']);
        $this->assertTrue($report->otherSheetPresence['KORMEER']);
        $this->assertFalse($report->otherSheetPresence['DAMIIN WALI KEENIN']);
        $this->assertFalse($report->otherSheetPresence['KUWA LA KANSALEY']);

        // CONFIRMED business rule: EVERY Excel-migrated person — regardless of status — is
        // treated as having an incomplete HR profile. This is a reporting-only dimension
        // (never written live), never empty, and never a substitute for reviewReasons/status.
        // Cancelled, Waiting, and Green/working people are all explicitly valid combinations.
        foreach ([...$report->readyToImport, ...$report->manualReview] as $person) {
            $this->assertNotEmpty($person->profileIncompleteReasons, "{$person->fullName} has no profileIncompleteReasons — every migrated person must have at least one.");
        }

        $this->assertSame(count($report->readyToImport) + count($report->manualReview), $report->profileIncompleteCount);

        $cancelledForCompleteness = $ready->get('Cancelled Person') ?? $review->get('Cancelled Person');
        $this->assertNotNull($cancelledForCompleteness);
        $this->assertTrue($cancelledForCompleteness->isCancellation);
        $this->assertNotEmpty($cancelledForCompleteness->profileIncompleteReasons);

        $waitingForCompleteness = $ready->get('Waiting Person') ?? $review->get('Waiting Person');
        $this->assertNotNull($waitingForCompleteness);
        $this->assertSame(EmployeeStatus::Waiting, $waitingForCompleteness->status);
        $this->assertNotEmpty($waitingForCompleteness->profileIncompleteReasons);

        $greenForCompleteness = $ready->get('Green Person') ?? $review->get('Green Person');
        $this->assertNotNull($greenForCompleteness);
        $this->assertTrue($greenForCompleteness->isGreenFlagged);
        $this->assertNotEmpty($greenForCompleteness->profileIncompleteReasons);
    }

    private function buildFixtureWorkbook(string $path): void
    {
        $spreadsheet = new Spreadsheet;
        $spreadsheet->removeSheetByIndex(0);

        $registration = $spreadsheet->createSheet();
        $registration->setTitle('REGISTRATION');
        $registration->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage', 'Experience', 'Other Contact', 'Other Contact Names', 'Other information', 'Training Fee'], null, 'A1');
        $registration->fromArray(['22/11/2018', 'Zamzam Hassan Osman', '615905335', 'Nadaafad', 'Hodan', '20', 'Gabar', 'Hooyo', 'Facebook', 'need training', 'none', null, null, null, null], null, 'A2');
        $registration->fromArray(['22/11/2018', 'Fee Paid Person', '615000001', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, 'paid'], null, 'A3');
        $registration->fromArray(['22/11/2018', 'Fee Ambiguous Person', '615000002', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, '0.95'], null, 'A4');
        $registration->fromArray(['22/11/2018', 'Unknown Stage Person', '615000003', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'Trained worked Atlantic', null, null, null, null, null], null, 'A5');
        $registration->fromArray([null, 'Alice One', '619999999', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A6');
        $registration->fromArray(['22/11/2018', 'Yellow Person', '615000010', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A7');
        $registration->fromArray(['22/11/2018', 'Red Only Person', '615000011', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A8');
        $registration->fromArray(['22/11/2018', 'Green Person', '615000012', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A9');
        $registration->fromArray(['22/11/2018', 'Wiilasha Matched Person', '615000013', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A10');
        $registration->fromArray(['22/11/2018', 'Waiting Matched Person', '615000014', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A11');
        $registration->fromArray(['22/11/2018', 'Red And Canceled Person', '615000016', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A12');
        // Deliberately NO fill color here — an ordinary registration entry, later cancelled
        // via the CANCELED sheet only (see the regression test this reproduces).
        $registration->fromArray(['22/11/2018', 'Registered Then Canceled Person', '615000017', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A13');
        // Appended after row 13 (not interleaved) so existing "row N" assertions above
        // never shift. Three more green people, one per remaining confirmed shade —
        // all four shades must be treated as IDENTICAL business context.
        $registration->fromArray(['22/11/2018', 'Green Person B', '615000018', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A14');
        $registration->fromArray(['22/11/2018', 'Green Person C', '615000019', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A15');
        $registration->fromArray(['22/11/2018', 'Green Person D', '615000020', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A16');
        // Appended after row 16 for the same reason as above — the FINAL confirmed color
        // rule ("every other color" and "no fill" both mean guarantor needed) plus the
        // guarantor-need-independent-of-pipeline-stage regression tests.
        $registration->fromArray(['22/11/2018', 'Other Color Person', '615000021', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A17');
        $registration->fromArray(['22/11/2018', 'Green Damiin Override Person', '615000022', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A18');
        $registration->fromArray(['22/11/2018', 'Waiting Damiin Override Person', '615000023', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A19');
        $registration->fromArray(['22/11/2018', 'Damiin Independent Stage Person', '615000024', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, null, null, null, null], null, 'A20');
        $registration->fromArray(['22/11/2018', 'Yellow With Secondary Contact Person', '615000025', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, '615333444', 'Secondary Contact Real Name', null, null], null, 'A21');
        $registration->fromArray(['22/11/2018', 'Unreliable Secondary Contact Person', '615000026', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training', null, 'n/a', '1', null, null], null, 'A22');

        // REGISTRATION row highlight colors — a confirmed Fayadhowr business signal.
        $registration->getStyle('B7')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF00'); // Yellow Person
        $registration->getStyle('B8')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FF0000'); // Red Only Person
        $registration->getStyle('B9')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('92D050'); // Green Person
        $registration->getStyle('B12')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FF0000'); // Red And Canceled Person
        $registration->getStyle('B14')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('00B050'); // Green Person B
        $registration->getStyle('B15')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('548135'); // Green Person C
        $registration->getStyle('B16')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('53CD93'); // Green Person D
        $registration->getStyle('B17')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFC000'); // Other Color Person — CONFIRMED: other color = guarantor needed
        $registration->getStyle('B18')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('92D050'); // Green Damiin Override Person
        $registration->getStyle('B21')->getFill()->setFillType(Fill::FILL_SOLID)->getStartColor()->setRGB('FFFF00'); // Yellow With Secondary Contact Person
        // Rows 19, 20, 22 deliberately left with NO fill — CONFIRMED: no fill = guarantor needed.

        $wiilasha = $spreadsheet->createSheet();
        $wiilasha->setTitle('wiilasha');
        $wiilasha->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Matial Status', 'Live with', 'Reference', null, 'Experience', 'Other Contact'], null, 'A1');
        $wiilasha->fromArray(['13/1/2019', 'Wiilasha Matched Person', '615000013', 'Machines', 'Somewhere', '25', 'Single', 'Reerkiisa', 'Indhey', null, null, null], null, 'A2');
        $wiilasha->fromArray(['13/1/2019', 'Wiilasha New Candidate', '615000015', 'Machines', 'Somewhere', '25', 'Single', 'Reerkiisa', 'Indhey', null, null, null], null, 'A3');

        $homeCleaning = $spreadsheet->createSheet();
        $homeCleaning->setTitle('Home Cleaning');
        $homeCleaning->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A1');
        $homeCleaning->fromArray(['25/11/2018', 'Zamzam Hassan Osman', '615905335', 'Xaafad', 'Hodan', '20', 'Gabar', 'Hooyo', 'Facebook', 'need training'], null, 'A2');
        $homeCleaning->fromArray([null, 'Bob Two', '619999999', 'Xaafad', 'Hodan', '30', 'Married', 'Hooyo', 'Facebook', null], null, 'A3');
        // CONFIRMED business structure: Home Team category + structured Work Type.
        $homeCleaning->fromArray(['25/11/2018', 'Home Cleaning Jiif Person', '615000031', 'Xafad jiif', 'Hodan', '20', 'Gabar', 'Hooyo', 'Facebook', 'need training'], null, 'A4');
        $homeCleaning->fromArray(['25/11/2018', 'Home Cleaning Maalin Person', '615000032', 'Xafad malin', 'Hodan', '20', 'Gabar', 'Hooyo', 'Facebook', 'need training'], null, 'A5');

        $waitingList = $spreadsheet->createSheet();
        $waitingList->setTitle('Waiting List');
        $waitingList->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Matial status', 'Live with', 'Reference', 'Ready'], null, 'A1');
        $waitingList->fromArray(['25-11-2018', 'Waiting Person', '615000004', 'Nadaafad', 'Kaaraan', '23', 'Garoob', 'Hooyo', 'Facebook', null], null, 'A2');
        $waitingList->fromArray(['25-11-2018', 'Waiting Matched Person', '615000014', 'Nadaafad', 'Kaaraan', '23', 'Garoob', 'Hooyo', 'Facebook', null], null, 'A3');
        // Same phone as REGISTRATION's "Waiting Damiin Override Person" — proves the Waiting
        // override wins even when the NEED DAMIIN sheet also has a real signal for this person.
        $waitingList->fromArray(['25-11-2018', 'Waiting Damiin Override Person', '615000023', 'Nadaafad', 'Kaaraan', '23', 'Garoob', 'Hooyo', 'Facebook', null], null, 'A4');

        $damiin = $spreadsheet->createSheet();
        $damiin->setTitle('NEED DAMIIN');
        $damiin->fromArray(['15/05/2025', 'Damiin Needed Person', '615000005', 'General cleaning', 'warshadaha', '19', 'Gabar', 'Abti', 'Tiktok', 'need training', 'no experience', '615795314', 'walashayd Naima', null, 'paid'], null, 'A1');
        // Same phone as REGISTRATION's "Green Damiin Override Person" — green must override
        // this sheet's guarantor-need signal to NO, even though this sheet's own signal is real.
        $damiin->fromArray(['15/05/2025', 'Green Damiin Override Person', '615000022', 'General cleaning', 'warshadaha', '19', 'Gabar', 'Abti', 'Tiktok', 'need training', 'no experience', null, null, null, null], null, 'A2');
        // Same phone as REGISTRATION's "Waiting Damiin Override Person" — Waiting must override
        // this sheet's guarantor-need signal to NO.
        $damiin->fromArray(['15/05/2025', 'Waiting Damiin Override Person', '615000023', 'General cleaning', 'warshadaha', '19', 'Gabar', 'Abti', 'Tiktok', 'need training', 'no experience', null, null, null, null], null, 'A3');
        // Same phone as REGISTRATION's "Damiin Independent Stage Person" — REGRESSION test:
        // guarantor-need must NOT disappear just because REGISTRATION's own Stage ("need
        // training") ranks equally and used to win the old rank-based tie-break.
        $damiin->fromArray(['15/05/2025', 'Damiin Independent Stage Person', '615000024', 'General cleaning', 'warshadaha', '19', 'Gabar', 'Abti', 'Tiktok', 'need training', 'no experience', null, null, null, null], null, 'A4');

        $cancelled = $spreadsheet->createSheet();
        $cancelled->setTitle('CANCELED');
        $cancelled->fromArray(['15/05/2025', 'Cancelled Person', '615000006', 'General cleaning', 'Daynile', '25', 'Garoob', 'Hooyo', 'mowlid', 'need training', 'no experience', '612682417', 'Hooyo Xamiido', null, 'MARABI AYEY DHAHDEY'], null, 'A1');
        // No valid phone at all — must NOT vanish into missingPhoneCount unreviewed; still
        // surfaces as a cancelled candidate for manual review (identity unverifiable).
        $cancelled->fromArray(['15/05/2025', 'Cancelled No Phone Person', 'n/a', 'General cleaning', 'Daynile', '25', 'Garoob', 'Hooyo', 'mowlid', 'need training', 'no experience', null, null, null, null], null, 'A2');
        // Same person as REGISTRATION's red row 12 — confirmed by BOTH cancellation sources.
        $cancelled->fromArray(['15/05/2025', 'Red And Canceled Person', '615000016', 'General cleaning', 'Daynile', '25', 'Garoob', 'Hooyo', 'mowlid', 'need training', 'no experience', null, null, null, null], null, 'A3');
        // Same person as REGISTRATION's ORDINARY (uncolored) row 13 — the only cancellation
        // signal for this person is this CANCELED row; the regression this reproduces wrongly
        // classified it as a "duplicate" of the unrelated ordinary row instead of the sole signal.
        $cancelled->fromArray(['15/05/2025', 'Registered Then Canceled Person', '615000017', 'General cleaning', 'Daynile', '25', 'Garoob', 'Hooyo', 'mowlid', 'need training', 'no experience', null, null, null, null], null, 'A4');
        // CONFIRMED business rule: the Training-Fee-position column is NOT reliably a
        // cancellation reason — "paid" here is fee-status noise, never the reason, and must
        // not be presented as one (it still shows up in cancellationSourceContext for full
        // traceability, just not asserted as cancellationReason).
        $cancelled->fromArray(['15/05/2025', 'Cancelled Paid Noise Person', '615000029', 'General cleaning', 'Daynile', '25', 'Garoob', 'Hooyo', 'mowlid', 'need training', 'no experience', null, null, null, 'paid'], null, 'A5');
        // A genuine, non-noise value in that same column position IS preserved as the
        // cancellation reason, verbatim.
        $cancelled->fromArray(['15/05/2025', 'Cancelled Real Reason Person', '615000030', 'General cleaning', 'Daynile', '25', 'Garoob', 'Hooyo', 'mowlid', 'need training', 'no experience', null, null, null, 'left the country'], null, 'A6');

        $newWaiting = $spreadsheet->createSheet();
        $newWaiting->setTitle('NEW WAITING LIST2026');
        $newWaiting->fromArray([null, null, null, null, null, null], null, 'A1');
        $newWaiting->fromArray(['Name', 'Tell', 'Job', 'Location', 'Xarun Practical Days', 'Dhisme Practical'], null, 'A2');
        $newWaiting->fromArray(['No Date Person', '615000007', 'General cleaning', 'Shibis', '20', 'Gabar', 'Hooyo', 'Tik Tok', 'Need Training', 'No Experience', '615727540', 'Hooyo HINDIYO', null, 'paid'], null, 'A3');

        $studentPractical = $spreadsheet->createSheet();
        $studentPractical->setTitle('Student Practical training');
        $studentPractical->fromArray([null, 'Student Practical Person', '615000008'], null, 'A1');

        $mogadishu = $spreadsheet->createSheet();
        $mogadishu->setTitle('MOGADISHU HOSPITAL');
        $mogadishu->fromArray(['Name', 'Time', 'Location', 'Shift needed', 'Comments', '% nadafada'], null, 'A1');
        $mogadishu->fromArray(['1', 'Hospital Roster Name', '8.3', 'Kawagodey', 'Night', null], null, 'A2');

        $jobOrders = $spreadsheet->createSheet();
        $jobOrders->setTitle('Job Orders');
        $jobOrders->fromArray(['Date', 'Customer Name', 'Tell', 'Location', 'Job Offer'], null, 'A1');
        $jobOrders->fromArray(['44265', 'Job Orders Customer', '611500198', 'Bula xubey', 'Cleaner'], null, 'A2');

        $payroll = $spreadsheet->createSheet();
        $payroll->setTitle('Payroll');
        $payroll->fromArray(['Date', 'Name', 'Tell', 'Days'], null, 'A1');
        $payroll->fromArray([null, 'Payroll Only Person', '615000009', '16'], null, 'A2');

        $kormeer = $spreadsheet->createSheet();
        $kormeer->setTitle('KORMEER');

        $cookingCentre = $spreadsheet->createSheet();
        $cookingCentre->setTitle('Cooking Centre');
        $cookingCentre->fromArray(['Cooking Centre'], null, 'A1');
        $cookingCentre->fromArray(['Date', 'Name', 'Tell', 'Job', 'Location', 'Age', 'Marital Status', 'Live with', 'Reference', 'Stage'], null, 'A2');
        // CONFIRMED business structure: Cooking category + structured Specialization.
        $cookingCentre->fromArray(['25/11/2018', 'Cooking Cook Person', '615000033', 'Chef', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A3');
        $cookingCentre->fromArray(['25/11/2018', 'Cooking Combined Person', '615000034', 'Cunto karis nadaafad', 'Hodan', '25', 'Single', 'Hooyo', 'Facebook', 'need training'], null, 'A4');

        // Sheets referenced by SheetConfig::registry() but not needed for this test's
        // assertions still need to exist so the service doesn't skip real coverage silently.
        foreach (['Waiters Centre', 'Sheet2', 'Supervisors', 'Other Jobs'] as $extra) {
            $sheet = $spreadsheet->createSheet();
            $sheet->setTitle($extra);
        }

        $writer = new Xlsx($spreadsheet);
        $writer->save($path);
    }
}
