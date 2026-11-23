<?php

namespace App\Models;

use App\Demonstrations\Classification\Classification;
use App\Demonstrations\Classification\PayloadClassifier;
use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use App\Enums\PayloadCategory;
use Carbon\CarbonImmutable;
use Database\Factories\ComparisonRecordFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One completed Vulnerable/Secure comparison in the demonstration history,
 * stored in `public.comparison_records` on the `pgsql` connection.
 *
 * Metadata only: there is no attribute for the submitted input, passwords,
 * generated SQL, bound parameters, returned rows or raw database errors.
 * Records are immutable once created. `user_id` is not mass assignable; set
 * it through the owner's comparisonRecords() relationship.
 *
 * @property-read int $id
 * @property-read int $user_id
 * @property-read DemonstrationType $demonstration_type
 * @property-read PayloadCategory $classification_category
 * @property-read array<string, list<string>> $classification_rules
 * @property-read int $classifier_version
 * @property-read string $vulnerable_status
 * @property-read int|null $vulnerable_row_count
 * @property-read bool $vulnerable_truncated
 * @property-read DemonstrationErrorCategory|null $vulnerable_error_category
 * @property-read int|null $vulnerable_duration_us
 * @property-read string $secure_status
 * @property-read int|null $secure_row_count
 * @property-read DemonstrationErrorCategory|null $secure_error_category
 * @property-read int $secure_duration_us
 * @property-read bool|null $results_differ
 * @property-read CarbonImmutable $created_at
 */
#[Fillable([
    'demonstration_type',
    'classification_category',
    'classification_rules',
    'classifier_version',
    'vulnerable_status',
    'vulnerable_row_count',
    'vulnerable_truncated',
    'vulnerable_error_category',
    'vulnerable_duration_us',
    'secure_status',
    'secure_row_count',
    'secure_error_category',
    'secure_duration_us',
    'results_differ',
])]
class ComparisonRecord extends Model
{
    /** @use HasFactory<ComparisonRecordFactory> */
    use HasFactory;

    /**
     * History records are never updated.
     */
    public const UPDATED_AT = null;

    /**
     * Timestamps are written with their UTC offset. The column is timestamptz
     * and the PostgreSQL session time zone can differ from the application's,
     * so a value without an offset would be read in the wrong zone.
     *
     * @var string
     */
    protected $dateFormat = 'Y-m-d H:i:sP';

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'demonstration_type' => DemonstrationType::class,
            'classification_category' => PayloadCategory::class,
            'classifier_version' => 'integer',
            'vulnerable_row_count' => 'integer',
            'vulnerable_truncated' => 'boolean',
            'vulnerable_error_category' => DemonstrationErrorCategory::class,
            'vulnerable_duration_us' => 'integer',
            'secure_row_count' => 'integer',
            'secure_error_category' => DemonstrationErrorCategory::class,
            'secure_duration_us' => 'integer',
            'results_differ' => 'boolean',
            'created_at' => 'immutable_datetime',
        ];
    }

    /**
     * Matched rule IDs per input field, read and written as an array.
     *
     * Always stored as a JSON object, as the column requires: the plain
     * `array` cast would store "no rules" as the JSON array `[]`.
     *
     * @return Attribute<array<string, list<string>>, array<string, list<string>>>
     */
    protected function classificationRules(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): array => $value === null ? [] : json_decode($value, true, flags: JSON_THROW_ON_ERROR),
            set: fn (array $value): string => $value === [] ? '{}' : json_encode($value, JSON_THROW_ON_ERROR),
        );
    }

    /**
     * Application-level immutability: an existing record can be neither
     * changed nor deleted through the model.
     */
    protected static function booted(): void
    {
        static::updating(fn (): never => throw new LogicException('Comparison records are immutable.'));
        static::deleting(fn (): never => throw new LogicException('Comparison records are immutable.'));
    }

    /**
     * The account that ran the comparison.
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Limit the query to the given user's own records.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function ownedBy(Builder $query, User $user): void
    {
        $query->where('user_id', $user->getKey());
    }

    /**
     * Newest first; records created in the same second by descending ID.
     *
     * @param  Builder<self>  $query
     */
    #[Scope]
    protected function newestFirst(Builder $query): void
    {
        $query->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * Apply validated history filters (ComparisonHistoryRequest::filters()).
     *
     * Dates are calendar days in the application time zone: `from` from the
     * start of that day, `to` through the end of it. Boundaries are bound
     * with their UTC offset, because the PostgreSQL session time zone can
     * differ. The user search is a case-insensitive literal match: %, _ and
     * the escape character in the search term are escaped.
     *
     * @param  Builder<self>  $query
     * @param  array{type?: string, category?: string, vulnerable_status?: string, secure_status?: string, altered?: string, from?: string, to?: string, user?: string}  $filters
     */
    #[Scope]
    protected function filtered(Builder $query, array $filters): void
    {
        $timezone = config('app.timezone');

        $query
            ->when($filters['type'] ?? null, fn (Builder $query, string $type) => $query->where('demonstration_type', $type))
            ->when($filters['category'] ?? null, fn (Builder $query, string $category) => $query->where('classification_category', $category))
            ->when($filters['vulnerable_status'] ?? null, fn (Builder $query, string $status) => $query->where('vulnerable_status', $status))
            ->when($filters['secure_status'] ?? null, fn (Builder $query, string $status) => $query->where('secure_status', $status))
            ->when($filters['altered'] ?? null, fn (Builder $query, string $altered) => match ($altered) {
                'yes' => $query->where('results_differ', true),
                'no' => $query->where('results_differ', false),
                default => $query->whereNull('results_differ'),
            })
            ->when($filters['from'] ?? null, fn (Builder $query, string $from) => $query->where(
                'created_at', '>=', CarbonImmutable::createFromFormat('!Y-m-d', $from, $timezone)->utc()->format('Y-m-d H:i:sP'),
            ))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to) => $query->where(
                'created_at', '<', CarbonImmutable::createFromFormat('!Y-m-d', $to, $timezone)->addDay()->utc()->format('Y-m-d H:i:sP'),
            ))
            ->when($filters['user'] ?? null, function (Builder $query, string $search): void {
                $pattern = '%'.addcslashes(mb_strtolower($search), '\\%_').'%';

                $query->whereHas('user', fn (Builder $users) => $users->where(fn (Builder $users) => $users
                    ->whereRaw("lower(name) like ? escape '\\'", [$pattern])
                    ->orWhereRaw("lower(email) like ? escape '\\'", [$pattern])));
            });
    }

    /**
     * The stored classification, rebuilt for display.
     *
     * Reads the raw stored JSON without the throwing accessor, and keeps only
     * string rule IDs from PayloadClassifier::EXPLANATIONS under the
     * demonstration's declared fields, once each. Malformed or forged stored
     * data therefore yields fewer rules, never an error or untrusted text.
     * Explanations always come from the allowlist.
     */
    public function storedClassification(): Classification
    {
        $raw = $this->attributes['classification_rules'] ?? null;
        $stored = is_string($raw) ? json_decode($raw, true) : null;
        $rulesByField = [];

        foreach (array_keys($this->demonstration_type->demonstration()->fields()) as $field) {
            $candidates = is_array($stored) ? ($stored[$field] ?? null) : null;

            if (! is_array($candidates)) {
                continue;
            }

            $ruleIds = array_values(array_unique(array_filter(
                $candidates,
                fn (mixed $ruleId): bool => is_string($ruleId) && array_key_exists($ruleId, PayloadClassifier::EXPLANATIONS),
            )));

            if ($ruleIds !== []) {
                $rulesByField[$field] = $ruleIds;
            }
        }

        return new Classification(
            category: $this->classification_category,
            rulesByField: $rulesByField,
            explanations: array_intersect_key(PayloadClassifier::EXPLANATIONS, array_flip(array_merge([], ...array_values($rulesByField)))),
            version: $this->classifier_version,
        );
    }
}
