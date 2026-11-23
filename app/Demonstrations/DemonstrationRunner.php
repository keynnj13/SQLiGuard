<?php

namespace App\Demonstrations;

use App\Enums\DemonstrationErrorCategory;
use App\Enums\DemonstrationType;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Log;
use PDOException;

/**
 * Runs one demonstration in both modes with exactly the same input.
 *
 * Vulnerable Mode runs first, on its own throw-away connection
 * (VulnerableQueryExecutor), and only in the local environment. Secure Mode
 * runs on the managed `lab` connection with bound parameters, and does not
 * depend on Vulnerable Mode being available. Neither mode ever uses the
 * application (`pgsql`) connection.
 */
final class DemonstrationRunner
{
    public function __construct(
        private readonly VulnerableQueryExecutor $executor,
        private readonly DatabaseManager $db,
    ) {}

    /**
     * @param  array<string, string>  $input
     */
    public function compare(DemonstrationType $type, array $input): Comparison
    {
        $demonstration = $type->demonstration();

        return new Comparison(
            type: $type,
            demonstration: $demonstration,
            input: $input,
            vulnerable: $this->runVulnerable($type, $demonstration, $input),
            secure: $this->runSecure($type, $demonstration, $input),
        );
    }

    /**
     * @param  array<string, string>  $input
     */
    private function runVulnerable(DemonstrationType $type, Demonstration $demonstration, array $input): ModeResult
    {
        $query = $demonstration->vulnerableQuery($input);

        if (! VulnerableMode::isAvailable()) {
            return new ModeResult(
                mode: ModeResult::VULNERABLE,
                status: ModeResult::REFUSED,
                sql: $query->sql(),
                segments: $query->segments,
                summary: 'Not executed.',
                message: 'Vulnerable Mode is only available when SQLiGuard runs in the local lab environment, so the server refused to execute it.',
            );
        }

        // The attempt includes opening the throw-away connection, the
        // transaction setup and the query, all inside the executor.
        $startedAt = hrtime(true);

        try {
            $result = $this->executor->execute($query, $demonstration->columns());
        } catch (PDOException $exception) {
            $durationMicroseconds = self::elapsedMicroseconds($startedAt);
            $errorCategory = $this->errorCategory($exception, $type, ModeResult::VULNERABLE);

            return new ModeResult(
                mode: ModeResult::VULNERABLE,
                status: ModeResult::FAILED,
                sql: $query->sql(),
                segments: $query->segments,
                executedSql: VulnerableQueryExecutor::wrap($query->sql(), $demonstration->columns()),
                summary: 'The database rejected the query.',
                message: $errorCategory->safeMessage(),
                errorCategory: $errorCategory,
                durationMicroseconds: $durationMicroseconds,
            );
        }

        $durationMicroseconds = self::elapsedMicroseconds($startedAt);

        return new ModeResult(
            mode: ModeResult::VULNERABLE,
            status: ModeResult::EXECUTED,
            sql: $query->sql(),
            segments: $query->segments,
            executedSql: $result['executedSql'],
            rows: $result['rows'],
            truncated: $result['truncated'],
            summary: $demonstration->summarize($result['rows'], $input),
            connectionId: $result['connectionId'],
            durationMicroseconds: $durationMicroseconds,
        );
    }

    /**
     * @param  array<string, string>  $input
     */
    private function runSecure(DemonstrationType $type, Demonstration $demonstration, array $input): ModeResult
    {
        // The attempt includes resolving the managed `lab` connection (which
        // connects lazily on first use) and the query. The diagnostic
        // pg_backend_pid() lookup afterwards is not part of the attempt.
        $startedAt = hrtime(true);
        $durationMicroseconds = null;
        $lab = $this->db->connection('lab');
        $query = $demonstration->secureQuery($lab->query(), $input)->limit(VulnerableQueryExecutor::ROW_LIMIT);

        try {
            $rows = $query->get()->map(fn (object $row): array => ModeResult::normalizeRow($row))->all();
            $durationMicroseconds = self::elapsedMicroseconds($startedAt);
            $connectionId = (int) $lab->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        } catch (PDOException $exception) {
            $durationMicroseconds ??= self::elapsedMicroseconds($startedAt);
            $errorCategory = $this->errorCategory($exception, $type, ModeResult::SECURE);

            return new ModeResult(
                mode: ModeResult::SECURE,
                status: ModeResult::FAILED,
                sql: $query->toSql(),
                bindings: $query->getBindings(),
                summary: 'The database rejected the query.',
                message: $errorCategory->safeMessage(),
                errorCategory: $errorCategory,
                durationMicroseconds: $durationMicroseconds,
            );
        }

        return new ModeResult(
            mode: ModeResult::SECURE,
            status: ModeResult::EXECUTED,
            sql: $query->toSql(),
            bindings: $query->getBindings(),
            rows: $rows,
            summary: $demonstration->summarize($rows, $input),
            connectionId: $connectionId,
            durationMicroseconds: $durationMicroseconds,
        );
    }

    /**
     * Categorize a database error. The category supplies the safe,
     * educational message (DemonstrationErrorCategory::safeMessage()).
     *
     * The raw exception message is never shown: it contains the SQL, host,
     * port and database name. Expected demonstration errors are not logged;
     * unexpected ones are logged with the SQLSTATE only (never the input).
     */
    private function errorCategory(PDOException $exception, DemonstrationType $type, string $mode): DemonstrationErrorCategory
    {
        // QueryException extends PDOException and carries the same errorInfo.
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());
        $category = DemonstrationErrorCategory::fromSqlState($sqlState);

        if ($category === DemonstrationErrorCategory::Unexpected) {
            Log::warning('Demonstration query failed unexpectedly.', [
                'demonstration' => $type->value,
                'mode' => $mode,
                'sqlstate' => $sqlState,
            ]);
        }

        return $category;
    }

    /**
     * Whole microseconds elapsed since an hrtime(true) reading. hrtime() is
     * monotonic, so the result never goes backwards with clock changes.
     */
    private static function elapsedMicroseconds(int $startedAtNanoseconds): int
    {
        return max(0, intdiv(hrtime(true) - $startedAtNanoseconds, 1000));
    }
}
