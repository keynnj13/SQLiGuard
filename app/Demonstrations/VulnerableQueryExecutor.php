<?php

namespace App\Demonstrations;

use Illuminate\Database\Connectors\ConnectionFactory;
use PDO;
use PDOStatement;
use RuntimeException;
use Throwable;

/**
 * Runs an intentionally vulnerable query with strict isolation. This is the
 * only place in SQLiGuard where string-built SQL is executed.
 *
 * Guarantees for every execution:
 *  - a brand-new `lab` connection (sqliguard_lab) created through the
 *    connection factory, never registered with the DatabaseManager, so no
 *    other code (including Secure Mode) can ever reuse it;
 *  - the connection is disconnected afterwards, so session settings changed
 *    by injected SQL (e.g. set_config('statement_timeout', ...)) die with it;
 *  - exactly one statement: the SQL is run with PDO::prepare()/execute()
 *    using native (server-side) prepares, which PostgreSQL refuses for
 *    multiple statements ("cannot insert multiple commands into a prepared
 *    statement"). PDO::exec(), which would accept stacked statements, is
 *    never given user input;
 *  - a READ ONLY transaction with a 5-second statement timeout, always
 *    rolled back;
 *  - a fixed outer wrapper that enforces the expected column list, returns
 *    at most ROW_LIMIT + 1 rows and truncates every value server-side.
 *
 * Preparing the statement does NOT make it safe: the input is already part of
 * the SQL text, so it is parsed as SQL. Preparing is used only to forbid
 * stacked statements.
 */
final class VulnerableQueryExecutor
{
    public const int ROW_LIMIT = 50;

    public const int VALUE_LENGTH_LIMIT = 200;

    public function __construct(private readonly ConnectionFactory $factory) {}

    /**
     * Wrap the generated query in a fixed, application-authored outer SELECT.
     *
     * The newlines matter: a `--` comment injected into the inner query ends
     * at the line break, so it cannot comment out the closing wrapper.
     *
     * @param  list<string>  $columns  application-defined column names
     */
    public static function wrap(string $sql, array $columns): string
    {
        $quoted = array_map(fn (string $column): string => '"'.$column.'"', $columns);
        $projection = implode(', ', array_map(
            fn (string $column): string => 'left(demo_result.'.$column.'::text, '.self::VALUE_LENGTH_LIMIT.') AS '.$column,
            $quoted,
        ));

        return 'SELECT '.$projection." FROM (\n".$sql."\n) AS demo_result(".implode(', ', $quoted).') LIMIT '.(self::ROW_LIMIT + 1);
    }

    /**
     * @param  list<string>  $columns
     * @return array{rows: list<array<string, string|null>>, truncated: bool, executedSql: string, connectionId: int}
     *
     * @throws \PDOException when the database rejects the query
     */
    public function execute(VulnerableQuery $query, array $columns): array
    {
        if (! VulnerableMode::isAvailable()) {
            throw new RuntimeException('Vulnerable Mode is only available in the local lab environment.');
        }

        $executedSql = self::wrap($query->sql(), $columns);
        $connection = $this->factory->make(config('database.connections.lab'), 'lab_vulnerable');
        $pdo = null;
        $statement = null;

        try {
            $pdo = $connection->getPdo();
            $pdo->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
            $pdo->setAttribute(self::disablePreparesAttribute(), false);

            $connectionId = (int) $pdo->query('SELECT pg_backend_pid()')->fetchColumn();

            $pdo->beginTransaction();
            $pdo->exec('SET TRANSACTION READ ONLY');
            $pdo->exec("SET LOCAL statement_timeout = '5s'");

            /** @var PDOStatement $statement */
            $statement = $pdo->prepare($executedSql);
            $statement->execute();
            $rows = $statement->fetchAll(PDO::FETCH_ASSOC);

            return [
                'rows' => array_slice($rows, 0, self::ROW_LIMIT),
                'truncated' => count($rows) > self::ROW_LIMIT,
                'executedSql' => $executedSql,
                'connectionId' => $connectionId,
            ];
        } finally {
            try {
                if ($pdo?->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable) {
                // The connection is discarded below either way.
            }

            $statement = null;
            $pdo = null;
            $connection->disconnect();
        }
    }

    private static function disablePreparesAttribute(): int
    {
        return defined('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            ? constant('Pdo\Pgsql::ATTR_DISABLE_PREPARES')
            : constant('PDO::PGSQL_ATTR_DISABLE_PREPARES');
    }
}
