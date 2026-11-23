<?php

namespace Tests\Unit\Demonstrations;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\Comparison;
use App\Demonstrations\ComparisonRecorder;
use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * The metadata ComparisonRecorder stores, built from hand-made results.
 * Persistence itself is covered by ComparisonRecorderPersistenceTest.
 */
class ComparisonRecorderTest extends TestCase
{
    private const string MARKER = 'ZQX-RECORDER-MARKER-7731';

    /**
     * @param  list<array<string, string|null>>  $rows
     */
    private static function executed(string $mode, array $rows, int $duration = 1_000, bool $truncated = false): ModeResult
    {
        return new ModeResult(mode: $mode, status: ModeResult::EXECUTED, sql: 'SELECT 1', rows: $rows, truncated: $truncated, summary: count($rows).' row(s).', durationMicroseconds: $duration);
    }

    private static function failed(string $mode, DemonstrationErrorCategory $category, int $duration = 900): ModeResult
    {
        return new ModeResult(mode: $mode, status: ModeResult::FAILED, sql: 'SELECT 1', summary: 'The database rejected the query.', message: $category->safeMessage(), errorCategory: $category, durationMicroseconds: $duration);
    }

    private static function refused(): ModeResult
    {
        return new ModeResult(mode: ModeResult::VULNERABLE, status: ModeResult::REFUSED, sql: 'SELECT 1', summary: 'Not executed.', message: 'Refused.');
    }

    /**
     * @param  array<string, string>  $input
     */
    private static function comparison(ModeResult $vulnerable, ModeResult $secure, DemonstrationType $type = DemonstrationType::Comment, array $input = ['team' => 'team-alpha']): Comparison
    {
        return new Comparison($type, $type->demonstration(), $input, $vulnerable, $secure);
    }

    private static function normal(): Classification
    {
        return new Classification(PayloadCategory::Normal, [], [], PayloadClassifier::VERSION);
    }

    /**
     * @return array<string, mixed>
     */
    private function attributes(Comparison $comparison, ?Classification $classification = null): array
    {
        return (new ComparisonRecorder)->attributesFor($comparison, $classification ?? self::normal());
    }

    public function test_it_stores_exactly_the_approved_metadata_columns(): void
    {
        $attributes = $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, []), self::executed(ModeResult::SECURE, [])));

        $this->assertEqualsCanonicalizing([
            'demonstration_type', 'classification_category', 'classification_rules', 'classifier_version',
            'vulnerable_status', 'vulnerable_row_count', 'vulnerable_truncated', 'vulnerable_error_category', 'vulnerable_duration_us',
            'secure_status', 'secure_row_count', 'secure_error_category', 'secure_duration_us',
            'results_differ',
        ], array_keys($attributes));
    }

    public function test_both_modes_are_stored_separately(): void
    {
        $privateRow = ['id' => '2', 'title' => 'Fake', 'owner_team' => 'team-alpha', 'visibility' => 'private'];
        $comparison = self::comparison(
            self::executed(ModeResult::VULNERABLE, [$privateRow, $privateRow, $privateRow], duration: 12_345),
            self::executed(ModeResult::SECURE, [], duration: 678),
        );

        $attributes = $this->attributes($comparison);

        $this->assertSame(DemonstrationType::Comment, $attributes['demonstration_type']);
        $this->assertSame(ModeResult::EXECUTED, $attributes['vulnerable_status']);
        $this->assertSame(3, $attributes['vulnerable_row_count']);
        $this->assertFalse($attributes['vulnerable_truncated']);
        $this->assertNull($attributes['vulnerable_error_category']);
        $this->assertSame(12_345, $attributes['vulnerable_duration_us']);
        $this->assertSame(ModeResult::EXECUTED, $attributes['secure_status']);
        $this->assertSame(0, $attributes['secure_row_count'], 'An executed mode with no rows has a count of 0, not null.');
        $this->assertNull($attributes['secure_error_category']);
        $this->assertSame(678, $attributes['secure_duration_us']);
        $this->assertTrue($attributes['results_differ']);
    }

    public function test_failed_and_refused_modes_keep_their_nulls(): void
    {
        $failed = $this->attributes(self::comparison(
            self::failed(ModeResult::VULNERABLE, DemonstrationErrorCategory::PermissionDenied),
            self::failed(ModeResult::SECURE, DemonstrationErrorCategory::Unexpected, duration: 300),
        ));

        $this->assertSame(ModeResult::FAILED, $failed['vulnerable_status']);
        $this->assertNull($failed['vulnerable_row_count']);
        $this->assertFalse($failed['vulnerable_truncated']);
        $this->assertSame(DemonstrationErrorCategory::PermissionDenied, $failed['vulnerable_error_category']);
        $this->assertSame(900, $failed['vulnerable_duration_us']);
        $this->assertSame(ModeResult::FAILED, $failed['secure_status']);
        $this->assertNull($failed['secure_row_count']);
        $this->assertSame(DemonstrationErrorCategory::Unexpected, $failed['secure_error_category']);
        $this->assertSame(300, $failed['secure_duration_us']);

        $refused = $this->attributes(self::comparison(self::refused(), self::executed(ModeResult::SECURE, [])));

        $this->assertSame(ModeResult::REFUSED, $refused['vulnerable_status']);
        $this->assertNull($refused['vulnerable_row_count']);
        $this->assertFalse($refused['vulnerable_truncated']);
        $this->assertNull($refused['vulnerable_error_category']);
        $this->assertNull($refused['vulnerable_duration_us']);
        $this->assertNull($refused['results_differ']);
    }

    public function test_truncation_is_stored_only_for_an_executed_vulnerable_mode(): void
    {
        $rows = array_fill(0, 50, ['id' => '1', 'title' => 'Fake', 'owner_team' => 'team-alpha', 'visibility' => 'public']);
        $truncated = $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, $rows, truncated: true), self::executed(ModeResult::SECURE, [])));

        $this->assertSame(50, $truncated['vulnerable_row_count']);
        $this->assertTrue($truncated['vulnerable_truncated']);

        $inconsistent = new ModeResult(mode: ModeResult::VULNERABLE, status: ModeResult::FAILED, sql: 'SELECT 1', truncated: true, errorCategory: DemonstrationErrorCategory::SyntaxError, durationMicroseconds: 5);
        $this->assertFalse($this->attributes(self::comparison($inconsistent, self::executed(ModeResult::SECURE, [])))['vulnerable_truncated']);
    }

    /**
     * @return array<string, array{ModeResult, ModeResult, bool|null}>
     */
    public static function outcomes(): array
    {
        $row = ['id' => '1', 'title' => 'Fake', 'owner_team' => 'team-alpha', 'visibility' => 'public'];

        return [
            'same rows' => [self::executed(ModeResult::VULNERABLE, [$row]), self::executed(ModeResult::SECURE, [$row]), false],
            'different rows' => [self::executed(ModeResult::VULNERABLE, [$row, $row]), self::executed(ModeResult::SECURE, [$row]), true],
            'vulnerable failed' => [self::failed(ModeResult::VULNERABLE, DemonstrationErrorCategory::SyntaxError), self::executed(ModeResult::SECURE, []), true],
            'both failed' => [self::failed(ModeResult::VULNERABLE, DemonstrationErrorCategory::SyntaxError), self::failed(ModeResult::SECURE, DemonstrationErrorCategory::Unexpected), true],
            'secure failed' => [self::executed(ModeResult::VULNERABLE, [$row]), self::failed(ModeResult::SECURE, DemonstrationErrorCategory::Unexpected), null],
            'vulnerable refused' => [self::refused(), self::executed(ModeResult::SECURE, [$row]), null],
        ];
    }

    #[DataProvider('outcomes')]
    public function test_results_differ_is_copied_from_input_altered_query(ModeResult $vulnerable, ModeResult $secure, ?bool $expected): void
    {
        $comparison = self::comparison($vulnerable, $secure);

        $this->assertSame($expected, $comparison->inputAlteredQuery());
        $this->assertSame($comparison->inputAlteredQuery(), $this->attributes($comparison)['results_differ']);
    }

    public function test_normal_input_stores_no_rules(): void
    {
        $classification = (new PayloadClassifier)->classify(DemonstrationType::Comment, ['team' => 'team-alpha']);

        $attributes = $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, []), self::executed(ModeResult::SECURE, [])), $classification);

        $this->assertSame(PayloadCategory::Normal, $attributes['classification_category']);
        $this->assertSame([], $attributes['classification_rules']);
    }

    public function test_attack_and_mixed_classifications_keep_every_matched_rule_under_its_field(): void
    {
        $classifier = new PayloadClassifier;
        $tautologyInput = ['username' => "x' OR '1'='1", 'password' => "anything' --"];
        $tautology = $classifier->classify(DemonstrationType::Tautology, $tautologyInput);
        $mixedInput = ['category' => "none' UNION SELECT id, codename, classification, budget FROM lab.demo_internal_projects --"];
        $mixed = $classifier->classify(DemonstrationType::Union, $mixedInput);

        $tautologyAttributes = $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, []), self::executed(ModeResult::SECURE, []), DemonstrationType::Tautology, $tautologyInput), $tautology);
        $mixedAttributes = $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, []), self::executed(ModeResult::SECURE, []), DemonstrationType::Union, $mixedInput), $mixed);

        $this->assertSame(PayloadCategory::Tautology, $tautologyAttributes['classification_category']);
        $this->assertSame(['username' => ['tautology.equal_literals', 'signal.quote'], 'password' => ['comment.line', 'signal.quote']], $tautologyAttributes['classification_rules']);
        $this->assertSame(PayloadCategory::Union, $mixedAttributes['classification_category']);
        $this->assertSame(['category' => ['union.select', 'comment.line', 'signal.quote', 'signal.sql_keyword']], $mixedAttributes['classification_rules']);
    }

    public function test_only_allowlisted_string_rule_ids_under_declared_fields_are_kept_once(): void
    {
        $forged = new Classification(
            category: PayloadCategory::Comment,
            rulesByField: [
                'team' => ['comment.line', 'not.a.rule', 'comment.line', 42, ['nested'], null, 'signal.quote', 'signal.quote'],
                'ghost_field' => ['union.select'],
                'password' => ['tautology.equal_literals'],
            ],
            explanations: ['comment.line' => 'FORGED-EXPLANATION', 'not.a.rule' => 'ARBITRARY-EXPLANATION'],
            version: PayloadClassifier::VERSION,
        );

        $attributes = $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, []), self::executed(ModeResult::SECURE, [])), $forged);

        $this->assertSame(['team' => ['comment.line', 'signal.quote']], $attributes['classification_rules']);
        $encoded = json_encode($attributes);
        foreach (['FORGED-EXPLANATION', 'ARBITRARY-EXPLANATION', 'not.a.rule', 'ghost_field', 'password', 'union.select'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
        foreach (PayloadClassifier::EXPLANATIONS as $explanation) {
            $this->assertStringNotContainsString($explanation, $encoded, 'Explanation text is never stored.');
        }
    }

    public function test_a_field_whose_rules_are_not_a_list_stores_nothing(): void
    {
        $forged = new Classification(PayloadCategory::Comment, ['team' => 'comment.line'], [], PayloadClassifier::VERSION);

        $this->assertSame([], $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, []), self::executed(ModeResult::SECURE, [])), $forged)['classification_rules']);
    }

    public function test_the_classifier_version_is_preserved(): void
    {
        $classification = new Classification(PayloadCategory::Unknown, ['team' => ['signal.quote']], [], 7);

        $attributes = $this->attributes(self::comparison(self::executed(ModeResult::VULNERABLE, []), self::executed(ModeResult::SECURE, [])), $classification);

        $this->assertSame(7, $attributes['classifier_version']);
        $this->assertSame(PayloadCategory::Unknown, $attributes['classification_category']);
    }

    public function test_input_sql_parameters_rows_summaries_and_messages_are_never_stored(): void
    {
        $markerRow = ['id' => '9', 'title' => self::MARKER.'-ROW', 'owner_team' => 'team-alpha', 'visibility' => 'private'];
        $vulnerable = new ModeResult(
            mode: ModeResult::VULNERABLE,
            status: ModeResult::EXECUTED,
            sql: "SELECT * FROM lab.demo_documents WHERE owner_team = '".self::MARKER."' --",
            segments: [['text' => self::MARKER, 'input' => true]],
            executedSql: 'SELECT wrapped '.self::MARKER,
            rows: [$markerRow],
            summary: self::MARKER.'-SUMMARY',
            message: self::MARKER.'-MESSAGE',
            connectionId: 4242,
            durationMicroseconds: 10,
        );
        $secure = new ModeResult(
            mode: ModeResult::SECURE,
            status: ModeResult::EXECUTED,
            sql: 'select * from "lab"."demo_documents" where "owner_team" = ?',
            bindings: [self::MARKER.'-BINDING'],
            rows: [],
            summary: self::MARKER.'-SECURE-SUMMARY',
            durationMicroseconds: 5,
        );
        $comparison = new Comparison(DemonstrationType::Comment, DemonstrationType::Comment->demonstration(), ['team' => self::MARKER."' --"], $vulnerable, $secure);
        $classification = (new PayloadClassifier)->classify(DemonstrationType::Comment, $comparison->input);

        $encoded = json_encode($this->attributes($comparison, $classification));

        foreach ([self::MARKER, 'SELECT', 'select', 'demo_documents', 'owner_team', '4242', 'wrapped'] as $forbidden) {
            $this->assertStringNotContainsString($forbidden, $encoded);
        }
    }
}
