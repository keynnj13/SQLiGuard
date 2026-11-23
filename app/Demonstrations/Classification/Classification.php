<?php

namespace App\Demonstrations\Classification;

use App\Enums\PayloadCategory;

/**
 * Result of classifying one demonstration submission. Read-only.
 *
 * Contains only allowlisted rule IDs, their fixed explanations, field names
 * and the classifier version. It never contains the submitted input.
 */
final class Classification
{
    /**
     * @param  array<string, list<string>>  $rulesByField  matched rule IDs per input field (fields without matches omitted)
     * @param  array<string, string>  $explanations  fixed explanation per matched rule ID
     */
    public function __construct(
        public readonly PayloadCategory $category,
        public readonly array $rulesByField,
        public readonly array $explanations,
        public readonly int $version,
    ) {}

    /**
     * Every matched rule ID across all fields, in the classifier's fixed rule order.
     *
     * @return list<string>
     */
    public function ruleIds(): array
    {
        return array_keys($this->explanations);
    }

    public function hasRule(string $ruleId): bool
    {
        return array_key_exists($ruleId, $this->explanations);
    }

    /**
     * Whether the primary category is an attack category (tautology, UNION
     * or comment) rather than normal or unknown.
     */
    public function isAttackPattern(): bool
    {
        return in_array($this->category, PayloadCategory::PRECEDENCE, true);
    }

    /**
     * Matched rules for display, grouped by field in the given field order.
     *
     * Only the given (declared) fields and allowlisted rule IDs are returned,
     * and every explanation is read from PayloadClassifier::EXPLANATIONS,
     * never from this object's own $explanations. A hand-built
     * Classification therefore cannot introduce arbitrary rules or text.
     *
     * @param  array<string, string>  $fieldLabels  field name => label, in display order
     * @return list<array{field: string, label: string, rules: list<array{id: string, explanation: string}>}>
     */
    public function displayRules(array $fieldLabels): array
    {
        $groups = [];

        foreach ($fieldLabels as $field => $label) {
            $rules = [];

            foreach (array_unique($this->rulesByField[$field] ?? []) as $ruleId) {
                if (is_string($ruleId) && array_key_exists($ruleId, PayloadClassifier::EXPLANATIONS)) {
                    $rules[] = ['id' => $ruleId, 'explanation' => PayloadClassifier::EXPLANATIONS[$ruleId]];
                }
            }

            if ($rules !== []) {
                $groups[] = ['field' => $field, 'label' => $label, 'rules' => $rules];
            }
        }

        return $groups;
    }
}
