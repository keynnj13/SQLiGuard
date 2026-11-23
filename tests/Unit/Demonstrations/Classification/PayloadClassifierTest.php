<?php

namespace Tests\Unit\Demonstrations\Classification;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PayloadClassifierTest extends TestCase
{
    /**
     * Classify a single UNION category value (the demonstration with one field).
     */
    private function classifyValue(string $value): Classification
    {
        return (new PayloadClassifier)->classify(DemonstrationType::Union, ['category' => $value]);
    }

    /**
     * @return array<string, array{DemonstrationType, array<string, string>}>
     */
    public static function normalInputs(): array
    {
        return [
            'tautology valid login' => [DemonstrationType::Tautology, ['username' => 'demo.analyst', 'password' => 'Analyst-Lab-2026']],
            'tautology wrong password' => [DemonstrationType::Tautology, ['username' => 'demo.analyst', 'password' => 'not-the-password']],
            'union category' => [DemonstrationType::Union, ['category' => 'stationery']],
            'comment team' => [DemonstrationType::Comment, ['team' => 'team-alpha']],
            'unicode text' => [DemonstrationType::Comment, ['team' => 'Café ☕ team Ω']],
        ];
    }

    /**
     * @param  array<string, string>  $input
     */
    #[DataProvider('normalInputs')]
    public function test_normal_input_matches_no_rule(DemonstrationType $type, array $input): void
    {
        $classification = (new PayloadClassifier)->classify($type, $input);

        $this->assertSame(PayloadCategory::Normal, $classification->category);
        $this->assertSame([], $classification->rulesByField);
        $this->assertSame([], $classification->explanations);
        $this->assertSame([], $classification->ruleIds());
    }

    /**
     * @return array<string, array{string, PayloadCategory, string}>
     */
    public static function primaryRuleInputs(): array
    {
        return [
            'quoted equal literals' => ["' OR '1'='1", PayloadCategory::Tautology, 'tautology.equal_literals'],
            'numeric equal literals' => ["' OR 1=1", PayloadCategory::Tautology, 'tautology.equal_literals'],
            'letter equal literals' => ["x' or 'a'='a", PayloadCategory::Tautology, 'tautology.equal_literals'],
            'parenthesised equal literals' => ["x') OR ('1'='1", PayloadCategory::Tautology, 'tautology.equal_literals'],
            'quoted and unquoted equal' => ["' OR '1'=1", PayloadCategory::Tautology, 'tautology.equal_literals'],
            'or true' => ["' OR true", PayloadCategory::Tautology, 'tautology.boolean_true'],
            'or not false' => ["' or not false", PayloadCategory::Tautology, 'tautology.boolean_true'],
            'or (true' => ["x' OR (TRUE", PayloadCategory::Tautology, 'tautology.boolean_true'],
            'union select' => ["none' UNION SELECT id, codename, classification, budget FROM lab.demo_internal_projects WHERE 'a'='a", PayloadCategory::Union, 'union.select'],
            'union all select' => ["none' UNION ALL SELECT 1, 'x', 'y', 0", PayloadCategory::Union, 'union.select'],
            'line comment' => ["team-alpha' --", PayloadCategory::Comment, 'comment.line'],
            'block comment' => ["team-alpha'/*", PayloadCategory::Comment, 'comment.block'],
        ];
    }

    #[DataProvider('primaryRuleInputs')]
    public function test_primary_rules_establish_their_category(string $value, PayloadCategory $category, string $ruleId): void
    {
        $classification = $this->classifyValue($value);

        $this->assertSame($category, $classification->category);
        $this->assertTrue($classification->hasRule($ruleId), "Expected rule {$ruleId}.");
    }

    public function test_unequal_literals_are_not_a_tautology(): void
    {
        $classification = $this->classifyValue("' OR '1'='2");

        $this->assertFalse($classification->hasRule('tautology.equal_literals'));
        $this->assertSame(PayloadCategory::Unknown, $classification->category);
    }

    /**
     * @return array<string, array{string, list<string>}>
     */
    public static function signalOnlyInputs(): array
    {
        return [
            'quote in a name' => ["O'Brien", ['signal.quote']],
            'statement separator' => ["x'; DROP TABLE t", ['signal.quote', 'signal.statement_separator', 'signal.sql_keyword']],
            'separator without quote' => ['1; select 1', ['signal.statement_separator', 'signal.sql_keyword']],
            'keyword in ordinary text' => ['please select a category', ['signal.sql_keyword']],
            'pg_sleep keyword' => ['pg_sleep(5)', ['signal.sql_keyword']],
        ];
    }

    /**
     * @param  list<string>  $expectedRules
     */
    #[DataProvider('signalOnlyInputs')]
    public function test_supporting_signals_alone_classify_as_unknown(string $value, array $expectedRules): void
    {
        $classification = $this->classifyValue($value);

        $this->assertSame(PayloadCategory::Unknown, $classification->category);
        $this->assertSame($expectedRules, $classification->ruleIds());
    }

    public function test_o_brien_is_unknown_because_of_the_quote_signal_only(): void
    {
        $classification = (new PayloadClassifier)->classify(DemonstrationType::Comment, ['team' => "O'Brien"]);

        $this->assertSame(PayloadCategory::Unknown, $classification->category);
        $this->assertSame(['team' => ['signal.quote']], $classification->rulesByField);
    }

    public function test_an_escaped_quote_pair_is_not_an_unescaped_quote(): void
    {
        $classification = $this->classifyValue("O''Brien");

        $this->assertSame(PayloadCategory::Normal, $classification->category);
    }

    /**
     * @return array<string, array{string, PayloadCategory, list<string>}>
     */
    public static function mixedInputs(): array
    {
        return [
            'tautology and comment' => ["' OR '1'='1' --", PayloadCategory::Tautology, ['tautology.equal_literals', 'comment.line', 'signal.quote']],
            'union and comment' => ["none' UNION SELECT 1, 'a', 'b', 2 --", PayloadCategory::Union, ['union.select', 'comment.line', 'signal.quote', 'signal.sql_keyword']],
            'union and tautology' => ["x' OR 1=1 UNION SELECT 1, 'a', 'b', 2 WHERE 'a'='a", PayloadCategory::Union, ['union.select', 'tautology.equal_literals', 'signal.quote', 'signal.sql_keyword']],
            'all three' => ["x' OR 'a'='a' UNION/**/SELECT 1, 'a', 'b', 2 --", PayloadCategory::Union, ['union.select', 'tautology.equal_literals', 'comment.line', 'comment.block', 'signal.quote', 'signal.sql_keyword']],
        ];
    }

    /**
     * @param  list<string>  $expectedRules
     */
    #[DataProvider('mixedInputs')]
    public function test_mixed_inputs_use_precedence_and_keep_every_matched_rule(string $value, PayloadCategory $category, array $expectedRules): void
    {
        $classification = $this->classifyValue($value);

        $this->assertSame($category, $classification->category);
        $this->assertSame($expectedRules, $classification->ruleIds());
        $this->assertSame(['category' => $expectedRules], $classification->rulesByField);
    }

    public function test_precedence_is_union_then_tautology_then_comment(): void
    {
        $this->assertSame([PayloadCategory::Union, PayloadCategory::Tautology, PayloadCategory::Comment], PayloadCategory::PRECEDENCE);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function normalizedVariants(): array
    {
        return [
            'mixed case union' => ["x' UnIoN SeLeCt 1, 'a', 'b', 2", 'union.select'],
            'mixed case tautology' => ["' oR '1'='1", 'tautology.equal_literals'],
            'mixed case boolean' => ["' Or NoT FaLsE", 'tautology.boolean_true'],
            'repeated spaces' => ["x' UNION      SELECT 1, 'a', 'b', 2", 'union.select'],
            'non-breaking space' => ["x' UNION\u{00A0}SELECT 1, 'a', 'b', 2", 'union.select'],
            'spaced comparison' => ["x' OR   1   =   1", 'tautology.equal_literals'],
            'comment as separator' => ["x' UNION/**/SELECT 1, 'a', 'b', 2", 'union.select'],
            'comment splitting a keyword' => ["x' UN/**/ION SELECT 1, 'a', 'b', 2", 'union.select'],
            'comment inside a tautology' => ["x' OR/**/1=1", 'tautology.equal_literals'],
        ];
    }

    #[DataProvider('normalizedVariants')]
    public function test_matching_is_case_whitespace_and_block_comment_insensitive(string $value, string $ruleId): void
    {
        $this->assertTrue($this->classifyValue($value)->hasRule($ruleId), "Expected rule {$ruleId}.");
    }

    /**
     * @return array<string, array{string, PayloadCategory}>
     */
    public static function documentedMisses(): array
    {
        return [
            'greater-than tautology without quote' => ['1 OR 2>1', PayloadCategory::Normal],
            'concatenation operator' => ['abc || def', PayloadCategory::Normal],
            'LIKE tautology' => ["x' OR 'a' LIKE 'a", PayloadCategory::Unknown],
            'dollar quoting' => ['$$ OR $$a$$=$$a$$', PayloadCategory::Normal],
            'CHR construction' => ['CHR(39) OR CHR(49)=CHR(49)', PayloadCategory::Normal],
        ];
    }

    #[DataProvider('documentedMisses')]
    public function test_documented_misses_are_not_labelled_as_attacks(string $value, PayloadCategory $category): void
    {
        $this->assertSame($category, $this->classifyValue($value)->category);
    }

    public function test_known_false_positives_are_labelled_as_documented(): void
    {
        $this->assertSame(PayloadCategory::Comment, $this->classifyValue('rock -- roll')->category);
        $this->assertSame(PayloadCategory::Union, $this->classifyValue('Union Select Deluxe Notebook')->category);
    }

    public function test_the_classifier_version_is_included(): void
    {
        $classification = $this->classifyValue("' OR '1'='1");

        $this->assertSame(1, PayloadClassifier::VERSION);
        $this->assertSame(PayloadClassifier::VERSION, $classification->version);
    }

    public function test_explanations_are_fixed_allowlisted_texts_that_never_contain_input(): void
    {
        $marker = 'ZQXMARKER';
        $input = [
            'username' => "{$marker}' OR '{$marker}'='{$marker}' -- {$marker} /* {$marker}; DROP {$marker}",
            'password' => "{$marker}' UNION SELECT {$marker} OR true",
        ];

        $classification = (new PayloadClassifier)->classify(DemonstrationType::Tautology, $input);

        $this->assertNotSame([], $classification->explanations);
        foreach ($classification->explanations as $ruleId => $explanation) {
            $this->assertSame(PayloadClassifier::EXPLANATIONS[$ruleId], $explanation);
            $this->assertStringNotContainsStringIgnoringCase($marker, $explanation);
        }
        $this->assertStringNotContainsStringIgnoringCase($marker, serialize($classification));
        foreach ($classification->rulesByField as $ruleIds) {
            $this->assertSame([], array_diff($ruleIds, array_keys(PayloadClassifier::EXPLANATIONS)));
        }
    }

    public function test_every_rule_has_a_fixed_explanation_without_placeholders(): void
    {
        $this->assertSame([
            'union.select', 'tautology.equal_literals', 'tautology.boolean_true', 'comment.line', 'comment.block',
            'signal.quote', 'signal.statement_separator', 'signal.sql_keyword',
        ], array_keys(PayloadClassifier::EXPLANATIONS));

        foreach (PayloadClassifier::EXPLANATIONS as $explanation) {
            $this->assertDoesNotMatchRegularExpression('/[{}%:]|\$\w/', $explanation);
        }
    }

    public function test_the_original_input_is_not_modified(): void
    {
        $input = ['username' => "  ' OR '1'='1' --  ", 'password' => "x/**/UN/**/ION\u{00A0}SELECT"];
        $copy = $input;

        (new PayloadClassifier)->classify(DemonstrationType::Tautology, $input);

        $this->assertSame($copy, $input);
    }

    public function test_classification_is_deterministic(): void
    {
        $classifier = new PayloadClassifier;
        $input = ['username' => "' OR '1'='1' --", 'password' => "x' UNION SELECT 1"];

        $this->assertEquals($classifier->classify(DemonstrationType::Tautology, $input), $classifier->classify(DemonstrationType::Tautology, $input));
    }

    public function test_only_the_demonstrations_own_fields_are_classified(): void
    {
        $classifier = new PayloadClassifier;
        $attack = "' OR '1'='1' --";
        $everyField = ['username' => $attack, 'password' => $attack, 'category' => $attack, 'team' => $attack, 'unexpected' => $attack];

        $this->assertSame(['username', 'password'], array_keys($classifier->classify(DemonstrationType::Tautology, $everyField)->rulesByField));
        $this->assertSame(['category'], array_keys($classifier->classify(DemonstrationType::Union, $everyField)->rulesByField));
        $this->assertSame(['team'], array_keys($classifier->classify(DemonstrationType::Comment, $everyField)->rulesByField));
        $this->assertSame(PayloadCategory::Normal, $classifier->classify(DemonstrationType::Union, ['team' => $attack, 'category' => 'stationery'])->category);
    }

    public function test_rules_are_grouped_by_the_field_they_matched(): void
    {
        $classification = (new PayloadClassifier)->classify(DemonstrationType::Tautology, [
            'username' => 'demo.analyst',
            'password' => "x') OR ('1'='1",
        ]);

        $this->assertSame(['password' => ['tautology.equal_literals', 'signal.quote']], $classification->rulesByField);
        $this->assertSame(PayloadCategory::Tautology, $classification->category);
    }

    /**
     * @return array<string, array{array<string, string|null>}>
     */
    public static function emptyOptionalPasswords(): array
    {
        return [
            'empty string' => [['username' => 'demo.analyst', 'password' => '']],
            'null' => [['username' => 'demo.analyst', 'password' => null]],
            'missing' => [['username' => 'demo.analyst']],
        ];
    }

    /**
     * @param  array<string, string|null>  $input
     */
    #[DataProvider('emptyOptionalPasswords')]
    public function test_an_empty_optional_password_is_classified_consistently(array $input): void
    {
        $classification = (new PayloadClassifier)->classify(DemonstrationType::Tautology, $input);

        $this->assertSame(PayloadCategory::Normal, $classification->category);
        $this->assertSame([], $classification->rulesByField);
    }

    public function test_every_phase_3_sample_receives_the_expected_label(): void
    {
        $expected = [
            'tautology' => ['valid-login' => 'normal', 'wrong-password' => 'normal', 'always-true-username' => 'tautology', 'always-true-password' => 'tautology'],
            'union' => ['stationery' => 'normal', 'electronics' => 'normal', 'union-internal-projects' => 'union', 'union-application-users' => 'union'],
            'comment' => ['team-alpha' => 'normal', 'team-beta' => 'normal', 'comment-out-visibility' => 'comment'],
        ];

        $actual = [];
        foreach (DemonstrationType::cases() as $type) {
            foreach ($type->demonstration()->samples() as $key => $sample) {
                $actual[$type->value][$key] = (new PayloadClassifier)->classify($type, $sample['values'])->category->value;
            }
        }

        $this->assertSame($expected, $actual);
    }

    public function test_invalid_utf8_does_not_break_classification(): void
    {
        // T1 validation rejects invalid UTF-8 before any execution; the
        // classifier must still behave safely if called directly. The matching
        // copy replaces the invalid byte, so the visible pattern is still found.
        $classification = $this->classifyValue("x\xFF' OR 1=1");

        $this->assertSame(PayloadCategory::Tautology, $classification->category);
        $this->assertSame(['tautology.equal_literals', 'signal.quote'], $classification->ruleIds());
    }
}
