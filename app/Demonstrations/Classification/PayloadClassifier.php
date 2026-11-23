<?php

namespace App\Demonstrations\Classification;

use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;

/**
 * Deterministic, rule-based payload classifier. AN EDUCATIONAL HEURISTIC.
 *
 * It labels recognizable SQL injection patterns so students can compare the
 * label with what actually happened in the two modes. It is NOT a SQL parser,
 * injection detector, firewall or security boundary, and it never decides
 * whether a query runs, never changes the input and never replaces
 * parameterized queries. Secure Mode is safe because of bound parameters,
 * not because of this classifier.
 *
 * Known misses (classified normal or unknown): OR 2>1, OR 'a' LIKE 'a', ||,
 * dollar quoting, E'' string escapes, CHR() construction, and keyword
 * splitting beyond the block-comment normalization below.
 * Known false positives: O'Brien (unknown), "rock -- roll" (comment), product
 * names containing "union select" (union).
 *
 * Matching uses private copies of each value (lowercased, whitespace
 * collapsed, block comments neutralized). The original input is never
 * modified and no part of it is ever returned.
 */
final class PayloadClassifier
{
    /**
     * Bump whenever a rule, pattern or precedence changes, so stored
     * classifications can be told apart.
     */
    public const int VERSION = 1;

    /**
     * Allowlisted rule IDs, in fixed order, with their fixed explanations.
     * Explanations never contain or depend on the submitted input.
     *
     * @var array<string, string>
     */
    public const array EXPLANATIONS = [
        'union.select' => 'Appends a second query with UNION SELECT. If its columns line up with the original query, rows from another table are added to the results.',
        'tautology.equal_literals' => 'Adds an OR condition that compares two identical values. Such a condition is always true, so the WHERE clause can match every row.',
        'tautology.boolean_true' => 'Adds an OR condition with an always-true Boolean value, so the WHERE clause can match every row.',
        'comment.line' => 'Starts a line comment. The database ignores the rest of the line, including any conditions written after the input.',
        'comment.block' => 'Starts a block comment, which can hide part of the query or split keywords to slip past simple filters.',
        'signal.quote' => 'Contains a single quote. A quote can end an SQL string early, but quotes also appear in ordinary text such as names, so this alone is not an attack.',
        'signal.statement_separator' => 'Contains a semicolon followed by an SQL keyword, which is how a second statement would be added. SQLiGuard never runs more than one statement.',
        'signal.sql_keyword' => 'Contains an SQL keyword as a whole word. Keywords also appear in ordinary text, so this alone is not an attack.',
    ];

    /**
     * The primary category each attack rule establishes. Rules not listed
     * here are supporting signals only.
     *
     * @var array<string, PayloadCategory>
     */
    private const array RULE_CATEGORIES = [
        'union.select' => PayloadCategory::Union,
        'tautology.equal_literals' => PayloadCategory::Tautology,
        'tautology.boolean_true' => PayloadCategory::Tautology,
        'comment.line' => PayloadCategory::Comment,
        'comment.block' => PayloadCategory::Comment,
    ];

    private const string KEYWORDS = 'select|insert|update|delete|drop|truncate|alter|create|grant|revoke|copy|sleep|pg_sleep';

    /**
     * Classify the demonstration's own fields. Fields of other
     * demonstrations are ignored; a missing field counts as ''.
     *
     * @param  array<string, string|null>  $input
     */
    public function classify(DemonstrationType $type, array $input): Classification
    {
        $rulesByField = [];

        foreach (array_keys($type->demonstration()->fields()) as $field) {
            $matched = $this->matchRules((string) ($input[$field] ?? ''));

            if ($matched !== []) {
                $rulesByField[$field] = $matched;
            }
        }

        $allRules = array_values(array_intersect(
            array_keys(self::EXPLANATIONS),
            array_merge(...array_values($rulesByField) ?: [[]]),
        ));

        return new Classification(
            category: $this->categoryFor($allRules),
            rulesByField: $rulesByField,
            explanations: array_intersect_key(self::EXPLANATIONS, array_flip($allRules)),
            version: self::VERSION,
        );
    }

    /**
     * Rule IDs matched by one value, in the fixed order of EXPLANATIONS.
     *
     * @return list<string>
     */
    private function matchRules(string $value): array
    {
        if ($value === '') {
            return [];
        }

        $lower = mb_strtolower($value);
        // Block comments as whitespace (PostgreSQL's own behavior: UNION/**/SELECT)
        // and removed entirely (keyword splitting such as UN/**/ION).
        $spaced = $this->collapseWhitespace(preg_replace('~/\*.*?\*/~su', ' ', $lower) ?? $lower);
        $joined = $this->collapseWhitespace(preg_replace('~/\*.*?\*/~su', '', $lower) ?? $lower);
        $variants = [$spaced, $joined];

        $checks = [
            'union.select' => fn (): bool => $this->anyMatch('/\bunion\s+(?:all\s+)?select\b/u', $variants),
            'tautology.equal_literals' => fn (): bool => $this->hasEqualLiteralTautology($spaced) || $this->hasEqualLiteralTautology($joined),
            'tautology.boolean_true' => fn (): bool => $this->anyMatch('/\bor\s*\(*\s*(?:true|not\s+false)\b/u', $variants),
            'comment.line' => fn (): bool => str_contains($lower, '--'),
            'comment.block' => fn (): bool => str_contains($lower, '/*'),
            'signal.quote' => fn (): bool => str_contains(str_replace("''", '', $value), "'"),
            'signal.statement_separator' => fn (): bool => $this->anyMatch('/;\s*(?:'.self::KEYWORDS.'|with|set|begin|commit)\b/u', $variants),
            'signal.sql_keyword' => fn (): bool => $this->anyMatch('/\b(?:'.self::KEYWORDS.')\b/u', $variants),
        ];

        return array_keys(array_filter($checks, fn (callable $check): bool => $check()));
    }

    /**
     * Whether an OR condition compares two identical literals, e.g.
     * OR '1'='1', OR 1=1, OR 'a'='a', or OR ('1'='1 where the template
     * supplies the closing quote. A quoted and an unquoted value compare
     * equal ('1'=1), as they do in SQL.
     */
    private function hasEqualLiteralTautology(string $matching): bool
    {
        $pattern = "/\\bor\\s*\\(*\\s*(?:'(?<left>[^']*)'|(?<leftNumber>\\d+))\\s*=\\s*\\(*\\s*(?:'(?<right>[^']*)'?|(?<rightNumber>\\d+))/u";

        if (! preg_match_all($pattern, $matching, $matches, PREG_SET_ORDER)) {
            return false;
        }

        foreach ($matches as $match) {
            $left = ($match['left'] ?? '') !== '' ? $match['left'] : ($match['leftNumber'] ?? '');
            $right = ($match['right'] ?? '') !== '' ? $match['right'] : ($match['rightNumber'] ?? '');

            if ($left !== '' && $left === $right) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $ruleIds
     */
    private function categoryFor(array $ruleIds): PayloadCategory
    {
        $matchedCategories = array_map(fn (string $id): PayloadCategory => self::RULE_CATEGORIES[$id], array_intersect($ruleIds, array_keys(self::RULE_CATEGORIES)));

        foreach (PayloadCategory::PRECEDENCE as $category) {
            if (in_array($category, $matchedCategories, true)) {
                return $category;
            }
        }

        return $ruleIds === [] ? PayloadCategory::Normal : PayloadCategory::Unknown;
    }

    /**
     * @param  list<string>  $subjects
     */
    private function anyMatch(string $pattern, array $subjects): bool
    {
        foreach ($subjects as $subject) {
            if (preg_match($pattern, $subject) === 1) {
                return true;
            }
        }

        return false;
    }

    private function collapseWhitespace(string $value): string
    {
        return trim(preg_replace('/[\s\p{Z}]+/u', ' ', $value) ?? $value);
    }
}
