<?php

namespace Database\Factories;

use App\Demonstrations\Classification\PayloadClassifier;
use App\Demonstrations\ModeResult;
use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use App\Models\ComparisonRecord;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * Metadata-only history records with no input, password, SQL or result row.
 * Rule IDs are allowlisted and filed under the demonstration's own fields.
 *
 * Every state that sets a mode's outcome sets all fields that depend on it
 * (status, row count, truncation, error category, duration), and
 * results_differ is always derived from the statuses as they are after the
 * state, exactly as Comparison::inputAlteredQuery() would. States can
 * therefore be chained in any order without breaking the CHECK constraints.
 *
 * @extends Factory<ComparisonRecord>
 */
class ComparisonRecordFactory extends Factory
{
    /**
     * A normal input: both modes executed and returned the same rows.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $rowCount = fake()->numberBetween(0, 3);

        return [
            'user_id' => User::factory(),
            'demonstration_type' => fake()->randomElement(DemonstrationType::cases()),
            'classification_category' => PayloadCategory::Normal,
            'classification_rules' => [],
            'classifier_version' => PayloadClassifier::VERSION,
            'vulnerable_status' => ModeResult::EXECUTED,
            'vulnerable_row_count' => $rowCount,
            'vulnerable_truncated' => false,
            'vulnerable_error_category' => null,
            'vulnerable_duration_us' => self::vulnerableDuration(),
            'secure_status' => ModeResult::EXECUTED,
            'secure_row_count' => $rowCount,
            'secure_error_category' => null,
            'secure_duration_us' => self::secureDuration(),
            'results_differ' => false,
        ];
    }

    /**
     * Normal input: both modes executed with the same rows and nothing matched.
     */
    public function normal(): static
    {
        return $this->state(function (array $attributes): array {
            $rowCount = fake()->numberBetween(0, 3);

            return [
                'classification_category' => PayloadCategory::Normal,
                'classification_rules' => [],
                'vulnerable_status' => ModeResult::EXECUTED,
                'vulnerable_row_count' => $rowCount,
                'vulnerable_truncated' => false,
                'vulnerable_error_category' => null,
                'vulnerable_duration_us' => $attributes['vulnerable_duration_us'] ?? self::vulnerableDuration(),
                'secure_status' => ModeResult::EXECUTED,
                'secure_row_count' => $rowCount,
                'secure_error_category' => null,
                'secure_duration_us' => $attributes['secure_duration_us'] ?? self::secureDuration(),
                'results_differ' => false,
            ];
        });
    }

    /**
     * A recognized attack pattern. Where a mode executed, its rows show the
     * attack's effect (extra rows in Vulnerable Mode, none in Secure Mode);
     * a mode that failed or was refused keeps its outcome.
     */
    public function attackPattern(): static
    {
        return $this->state(function (array $attributes): array {
            $preset = fake()->randomElement([
                [DemonstrationType::Tautology, PayloadCategory::Tautology, ['username' => ['tautology.equal_literals', 'signal.quote']], 4],
                [DemonstrationType::Union, PayloadCategory::Union, ['category' => ['union.select', 'signal.quote', 'signal.sql_keyword']], 3],
                [DemonstrationType::Comment, PayloadCategory::Comment, ['team' => ['comment.line', 'signal.quote']], 3],
            ]);

            $state = [
                'demonstration_type' => $preset[0],
                'classification_category' => $preset[1],
                'classification_rules' => $preset[2],
            ];

            if ($attributes['vulnerable_status'] === ModeResult::EXECUTED) {
                $state += ['vulnerable_row_count' => $preset[3], 'vulnerable_truncated' => false];
            }

            if ($attributes['secure_status'] === ModeResult::EXECUTED) {
                $state += ['secure_row_count' => 0];
            }

            return $state + [
                'results_differ' => self::resultsDiffer($attributes['vulnerable_status'], $attributes['secure_status'], rowsDiffer: true),
            ];
        });
    }

    /**
     * Vulnerable Mode reached the database, which rejected the altered SQL.
     */
    public function failedVulnerable(DemonstrationErrorCategory $category = DemonstrationErrorCategory::SyntaxError): static
    {
        return $this->state(fn (array $attributes): array => [
            'demonstration_type' => DemonstrationType::Union,
            'classification_category' => PayloadCategory::Unknown,
            'classification_rules' => ['category' => ['signal.quote']],
            'vulnerable_status' => ModeResult::FAILED,
            'vulnerable_row_count' => null,
            'vulnerable_truncated' => false,
            'vulnerable_error_category' => $category,
            'vulnerable_duration_us' => $attributes['vulnerable_duration_us'] ?? self::vulnerableDuration(),
            'results_differ' => self::resultsDiffer(ModeResult::FAILED, $attributes['secure_status'], rowsDiffer: true),
        ]);
    }

    /**
     * Vulnerable Mode was refused outside the local environment and never attempted.
     */
    public function refusedVulnerable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vulnerable_status' => ModeResult::REFUSED,
            'vulnerable_row_count' => null,
            'vulnerable_truncated' => false,
            'vulnerable_error_category' => null,
            'vulnerable_duration_us' => null,
            'results_differ' => self::resultsDiffer(ModeResult::REFUSED, $attributes['secure_status'], rowsDiffer: true),
        ]);
    }

    /**
     * Vulnerable Mode executed and returned more than the 50-row limit.
     */
    public function truncatedVulnerable(): static
    {
        return $this->state(fn (array $attributes): array => [
            'vulnerable_status' => ModeResult::EXECUTED,
            'vulnerable_row_count' => 50,
            'vulnerable_truncated' => true,
            'vulnerable_error_category' => null,
            'vulnerable_duration_us' => $attributes['vulnerable_duration_us'] ?? self::vulnerableDuration(),
            'results_differ' => self::resultsDiffer(ModeResult::EXECUTED, $attributes['secure_status'], rowsDiffer: true),
        ]);
    }

    /**
     * Secure Mode could not run (for example, the lab database was unavailable).
     */
    public function failedSecure(DemonstrationErrorCategory $category = DemonstrationErrorCategory::Unexpected): static
    {
        return $this->state(fn (array $attributes): array => [
            'secure_status' => ModeResult::FAILED,
            'secure_row_count' => null,
            'secure_error_category' => $category,
            'secure_duration_us' => $attributes['secure_duration_us'] ?? self::secureDuration(),
            'results_differ' => self::resultsDiffer($attributes['vulnerable_status'], ModeResult::FAILED, rowsDiffer: true),
        ]);
    }

    /**
     * Both modes reached the database and both failed.
     */
    public function bothFailed(
        DemonstrationErrorCategory $vulnerableCategory = DemonstrationErrorCategory::PermissionDenied,
        DemonstrationErrorCategory $secureCategory = DemonstrationErrorCategory::Unexpected,
    ): static {
        return $this->failedVulnerable($vulnerableCategory)->failedSecure($secureCategory);
    }

    /**
     * results_differ for the given statuses, with the same rules as
     * Comparison::inputAlteredQuery(): refused -> null, vulnerable failed ->
     * true, secure not executed -> null, both executed -> whether the rows differ.
     */
    private static function resultsDiffer(string $vulnerableStatus, string $secureStatus, bool $rowsDiffer): ?bool
    {
        return match (true) {
            $vulnerableStatus === ModeResult::REFUSED => null,
            $vulnerableStatus === ModeResult::FAILED => true,
            $secureStatus !== ModeResult::EXECUTED => null,
            default => $rowsDiffer,
        };
    }

    private static function vulnerableDuration(): int
    {
        return fake()->numberBetween(2_000, 40_000);
    }

    private static function secureDuration(): int
    {
        return fake()->numberBetween(200, 8_000);
    }
}
