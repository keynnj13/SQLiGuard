<?php

namespace App\Demonstrations;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Enums\DemonstrationType;
use App\Models\ComparisonRecord;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * Saves one metadata-only history record per completed comparison, on the
 * application (`pgsql`) connection through the owner's relationship.
 *
 * Only structured metadata is stored: statuses, row counts, truncation,
 * error categories, durations, the classification label, allowlisted rule
 * IDs and whether the results differed. The submitted input, generated or
 * executed SQL, bound parameters, returned rows, summaries and messages are
 * never read from the results, stored or logged.
 *
 * Recording runs after the comparison and classification are complete and
 * never changes them. A failed insert is reported by returning null, so the
 * completed comparison can still be shown.
 */
final class ComparisonRecorder
{
    /**
     * Save the history record for a completed comparison.
     *
     * The owner always comes from the given (authenticated) user, never from
     * request data. Returns null, after logging a fixed message with the
     * demonstration type, user ID and SQLSTATE only, when the insert fails.
     */
    public function record(User $user, Comparison $comparison, Classification $classification): ?ComparisonRecord
    {
        try {
            return $user->comparisonRecords()->create($this->attributesFor($comparison, $classification));
        } catch (PDOException $exception) {
            // QueryException extends PDOException. Neither the exception nor its
            // message is logged: it contains the SQL statement and its bindings.
            Log::warning('Comparison history could not be saved.', [
                'demonstration' => $comparison->type->value,
                'user_id' => $user->getKey(),
                'sqlstate' => (string) ($exception->errorInfo[0] ?? $exception->getCode()),
            ]);

            return null;
        }
    }

    /**
     * The metadata stored for a comparison, keyed by history column.
     *
     * @return array<string, mixed>
     */
    public function attributesFor(Comparison $comparison, Classification $classification): array
    {
        return [
            'demonstration_type' => $comparison->type,
            'classification_category' => $classification->category,
            'classification_rules' => $this->allowlistedRules($comparison->type, $classification),
            'classifier_version' => $classification->version,
            ...$this->modeAttributes('vulnerable', $comparison->vulnerable),
            ...$this->modeAttributes('secure', $comparison->secure),
            'results_differ' => $comparison->inputAlteredQuery(),
        ];
    }

    /**
     * One mode's outcome. Row count and truncation describe returned rows, so
     * they exist only for an executed mode; nothing unavailable becomes 0.
     *
     * @return array<string, mixed>
     */
    private function modeAttributes(string $prefix, ModeResult $result): array
    {
        $attributes = [
            "{$prefix}_status" => $result->status,
            "{$prefix}_row_count" => $result->executed() ? count($result->rows) : null,
            "{$prefix}_error_category" => $result->errorCategory,
            "{$prefix}_duration_us" => $result->durationMicroseconds,
        ];

        if ($prefix === 'vulnerable') {
            $attributes['vulnerable_truncated'] = $result->executed() && $result->truncated;
        }

        return $attributes;
    }

    /**
     * Matched rule IDs per field, keeping only string IDs from the canonical
     * PayloadClassifier::EXPLANATIONS allowlist under the demonstration's own
     * declared fields, without duplicates. Explanation text is never stored,
     * and Classification::ruleIds()/hasRule() are not used: they read the
     * object's own, unvalidated explanations.
     *
     * @return array<string, list<string>>
     */
    private function allowlistedRules(DemonstrationType $type, Classification $classification): array
    {
        $rules = [];

        foreach (array_keys($type->demonstration()->fields()) as $field) {
            $candidates = $classification->rulesByField[$field] ?? [];

            $ruleIds = is_array($candidates)
                ? array_filter($candidates, fn (mixed $ruleId): bool => is_string($ruleId) && array_key_exists($ruleId, PayloadClassifier::EXPLANATIONS))
                : [];

            $ruleIds = array_values(array_unique($ruleIds));

            if ($ruleIds !== []) {
                $rules[$field] = $ruleIds;
            }
        }

        return $rules;
    }
}
