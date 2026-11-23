<?php

namespace Tests\Unit\Demonstrations\Classification;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Enums\PayloadCategory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class ClassificationTest extends TestCase
{
    public function test_display_rules_take_explanations_only_from_the_allowlist(): void
    {
        $forged = new Classification(
            category: PayloadCategory::Comment,
            rulesByField: ['team' => ['comment.line', 'not.a.rule', 'signal.quote']],
            explanations: ['comment.line' => '<b>FORGED TEXT</b>', 'not.a.rule' => 'ARBITRARY TEXT', 'signal.quote' => 'ALSO FORGED'],
            version: 1,
        );

        $rules = $forged->displayRules(['team' => 'Team']);

        $this->assertSame([[
            'field' => 'team',
            'label' => 'Team',
            'rules' => [
                ['id' => 'comment.line', 'explanation' => PayloadClassifier::EXPLANATIONS['comment.line']],
                ['id' => 'signal.quote', 'explanation' => PayloadClassifier::EXPLANATIONS['signal.quote']],
            ],
        ]], $rules);
    }

    public function test_display_rules_include_only_declared_fields_in_their_given_order(): void
    {
        $classification = new Classification(
            category: PayloadCategory::Union,
            rulesByField: [
                'undeclared' => ['union.select'],
                'password' => ['signal.quote'],
                'username' => ['union.select', 'union.select'],
            ],
            explanations: [],
            version: 1,
        );

        $rules = $classification->displayRules(['username' => 'Username', 'password' => 'Password']);

        $this->assertSame(['username', 'password'], array_column($rules, 'field'));
        $this->assertSame(['Username', 'Password'], array_column($rules, 'label'));
        $this->assertSame([['id' => 'union.select', 'explanation' => PayloadClassifier::EXPLANATIONS['union.select']]], $rules[0]['rules']);
    }

    public function test_display_rules_are_empty_when_nothing_matched(): void
    {
        $classification = new Classification(PayloadCategory::Normal, [], [], 1);

        $this->assertSame([], $classification->displayRules(['team' => 'Team']));
    }

    /**
     * @return array<string, array{PayloadCategory, bool}>
     */
    public static function categories(): array
    {
        return [
            'normal' => [PayloadCategory::Normal, false],
            'unknown' => [PayloadCategory::Unknown, false],
            'tautology' => [PayloadCategory::Tautology, true],
            'union' => [PayloadCategory::Union, true],
            'comment' => [PayloadCategory::Comment, true],
        ];
    }

    #[DataProvider('categories')]
    public function test_only_tautology_union_and_comment_are_attack_patterns(PayloadCategory $category, bool $isAttack): void
    {
        $this->assertSame($isAttack, (new Classification($category, [], [], 1))->isAttackPattern());
    }
}
