<?php

namespace App\Demonstrations;

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

        try {
            $result = $this->executor->execute($query, $demonstration->columns());
        } catch (PDOException $exception) {
            return new ModeResult(
                mode: ModeResult::VULNERABLE,
                status: ModeResult::FAILED,
                sql: $query->sql(),
                segments: $query->segments,
                executedSql: VulnerableQueryExecutor::wrap($query->sql(), $demonstration->columns()),
                summary: 'The database rejected the query.',
                message: $this->safeErrorMessage($exception, $type, ModeResult::VULNERABLE),
            );
        }

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
        );
    }

    /**
     * @param  array<string, string>  $input
     */
    private function runSecure(DemonstrationType $type, Demonstration $demonstration, array $input): ModeResult
    {
        $lab = $this->db->connection('lab');
        $query = $demonstration->secureQuery($lab->query(), $input)->limit(VulnerableQueryExecutor::ROW_LIMIT);

        try {
            $rows = $query->get()->map(fn (object $row): array => ModeResult::normalizeRow($row))->all();
            $connectionId = (int) $lab->selectOne('SELECT pg_backend_pid() AS pid')->pid;
        } catch (PDOException $exception) {
            return new ModeResult(
                mode: ModeResult::SECURE,
                status: ModeResult::FAILED,
                sql: $query->toSql(),
                bindings: $query->getBindings(),
                summary: 'The database rejected the query.',
                message: $this->safeErrorMessage($exception, $type, ModeResult::SECURE),
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
        );
    }

    /**
     * Translate a database error into a safe, educational message.
     *
     * The raw exception message is never shown: it contains the SQL, host,
     * port and database name. Expected demonstration errors are not logged;
     * unexpected ones are logged with the SQLSTATE only (never the input).
     */
    private function safeErrorMessage(PDOException $exception, DemonstrationType $type, string $mode): string
    {
        // QueryException extends PDOException and carries the same errorInfo.
        $sqlState = (string) ($exception->errorInfo[0] ?? $exception->getCode());

        $message = match (true) {
            $sqlState === '42601' => 'Syntax error: the input changed the SQL text so that it is no longer a single valid statement, so the database rejected it.',
            $sqlState === '42501' => 'Permission denied: the restricted lab account is not allowed to read that object. Least privilege stopped this query.',
            $sqlState === '57014' => 'The query was cancelled by the 5-second statement timeout.',
            $sqlState === '25006' => 'The read-only demonstration transaction refused an attempt to change data.',
            str_starts_with($sqlState, '42') => 'The query referred to a table, column, function or type that the database could not use, or the UNION parts did not have matching columns.',
            str_starts_with($sqlState, '22') => 'The input produced a value the database could not convert or compare.',
            default => null,
        };

        if ($message !== null) {
            return $message;
        }

        Log::warning('Demonstration query failed unexpectedly.', [
            'demonstration' => $type->value,
            'mode' => $mode,
            'sqlstate' => $sqlState,
        ]);

        return 'The demonstration database could not run this query. Check that the local PostgreSQL lab is running and seeded.';
    }
}
