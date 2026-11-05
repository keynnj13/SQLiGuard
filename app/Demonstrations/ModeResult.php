<?php

namespace App\Demonstrations;

/**
 * The outcome of one mode (vulnerable or secure) for one comparison.
 * Temporary response data only; nothing is persisted in Phase 3.
 */
final class ModeResult
{
    public const string VULNERABLE = 'vulnerable';

    public const string SECURE = 'secure';

    public const string EXECUTED = 'executed';

    public const string FAILED = 'failed';

    public const string REFUSED = 'refused';

    /**
     * @param  string  $sql  the query text (vulnerable: with input; secure: with placeholders)
     * @param  list<array{text: string, input: bool}>  $segments  vulnerable query split by input origin
     * @param  list<string|int|float|bool|null>  $bindings  bound parameter values (secure only)
     * @param  list<array<string, string|null>>  $rows
     * @param  int|null  $connectionId  PostgreSQL backend PID (diagnostics/tests only, never displayed)
     */
    public function __construct(
        public readonly string $mode,
        public readonly string $status,
        public readonly string $sql,
        public readonly array $segments = [],
        public readonly array $bindings = [],
        public readonly ?string $executedSql = null,
        public readonly array $rows = [],
        public readonly bool $truncated = false,
        public readonly string $summary = '',
        public readonly ?string $message = null,
        public readonly ?int $connectionId = null,
    ) {}

    public function isVulnerable(): bool
    {
        return $this->mode === self::VULNERABLE;
    }

    public function executed(): bool
    {
        return $this->status === self::EXECUTED;
    }

    /**
     * Normalize a database row to strings so both modes display and compare alike.
     *
     * @param  array<string, mixed>|object  $row
     * @return array<string, string|null>
     */
    public static function normalizeRow(array|object $row): array
    {
        return array_map(fn (mixed $value): ?string => match (true) {
            $value === null => null,
            is_bool($value) => $value ? 'true' : 'false',
            default => (string) $value,
        }, (array) $row);
    }
}
