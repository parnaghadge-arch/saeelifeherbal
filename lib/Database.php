<?php
/**
 * PDO database access layer.
 *
 * Provides a thin, safe query builder on top of PDO with prepared statements only,
 * nested transaction support, a portable SQL dialect layer (MySQL 8 / SQLite) and
 * slow-query logging. All SQL in the application flows through this class.
 */
declare(strict_types=1);

namespace Saee\Lib;

use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;
use Throwable;

final class Database
{
    private static ?Database $instance = null;

    private PDO $pdo;
    private string $driver;
    private int $transactionLevel = 0;
    private array $queryLog = [];
    private bool $logQueries = false;
    private array $config;

    private function __construct(array $config)
    {
        $this->config = $config;
        $this->driver = $config['driver'] ?? 'mysql';
        $this->logQueries = (bool) (getenv('SAEE_LOG_QUERIES') ?: false);
        $this->connect();
    }

    public static function instance(?array $config = null): Database
    {
        if (self::$instance === null) {
            if ($config === null) {
                $config = Config::get('database.db');
            }
            self::$instance = new self($config);
        }
        return self::$instance;
    }

    /** Testing helper. */
    public static function reset(): void
    {
        self::$instance = null;
    }

    private function connect(): void
    {
        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_STRINGIFY_FETCHES  => false,
        ];

        try {
            if ($this->driver === 'sqlite') {
                $path = $this->config['sqlite_path'];
                @mkdir(dirname($path), 0775, true);
                $this->pdo = new PDO('sqlite:' . $path, null, null, $options);
                $this->pdo->exec('PRAGMA foreign_keys = ON');
                $this->pdo->exec('PRAGMA journal_mode = WAL');
                $this->pdo->exec('PRAGMA busy_timeout = 5000');
            } else {
                $c = $this->config;
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $c['host'], (int) $c['port'], $c['database'], $c['charset'] ?? 'utf8mb4');
                try {
                    $this->pdo = new PDO($dsn, $c['username'], $c['password'], $options);
                } catch (PDOException $e) {
                    // 1049 = unknown database. A fresh XAMPP user usually has not created it yet,
                    // so create it once (utf8mb4) and reconnect instead of failing the install.
                    if ((int) $e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                        $serverDsn = sprintf('mysql:host=%s;port=%d;charset=%s', $c['host'], (int) $c['port'], $c['charset'] ?? 'utf8mb4');
                        $server = new PDO($serverDsn, $c['username'], $c['password'], $options);
                        $name = str_replace('`', '', (string) $c['database']);
                        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        $this->pdo = new PDO($dsn, $c['username'], $c['password'], $options);
                    } else {
                        throw $e;
                    }
                }
                $this->pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
                $this->pdo->exec("SET time_zone = '+05:30'");
            }
        } catch (PDOException $e) {
            throw new RuntimeException(self::explainConnectionError($e, $this->driver), 0, $e);
        }
    }

    public function pdo(): PDO
    {
        return $this->pdo;
    }

    /** Turn a raw PDO connection error into something an operator can act on. */
    private static function explainConnectionError(PDOException $e, string $driver): string
    {
        $raw = $e->getMessage();
        if ($driver !== 'mysql') {
            return 'Database connection failed: ' . $raw;
        }
        $hint = match (true) {
            (int) $e->getCode() === 1045 || str_contains($raw, 'Access denied') => 'MySQL rejected the username/password. On XAMPP the default is user "root" with an empty password.',
            (int) $e->getCode() === 2002 || str_contains($raw, 'No such file or directory') || str_contains($raw, 'refused') => 'MySQL is not running or is listening on another port (usually 3306). Start MySQL in the XAMPP control panel.',
            (int) $e->getCode() === 1049 => 'The database does not exist and could not be created. Create it in phpMyAdmin (utf8mb4) or use an account allowed to CREATE DATABASE.',
            str_contains($raw, 'could not find driver') => 'The pdo_mysql extension is disabled. Uncomment extension=pdo_mysql in php.ini and restart.',
            default => '',
        };
        return 'Database connection failed: ' . $raw . ($hint !== '' ? ' — ' . $hint : '');
    }

    public function driver(): string
    {
        return $this->driver;
    }

    /**
     * Named placeholders used by a statement, in the order they appear.
     * Placeholders inside quoted SQL literals are ignored so a JSON or text value in the
     * query cannot be mistaken for a placeholder.
     *
     * @return list<array{0:string,1:int,2:int}> [name, offset, length]
     */
    private static function placeholderTokens(string $sql): array
    {
        // Blank out single/double quoted literals first, keeping byte offsets intact.
        $masked = preg_replace_callback(
            ["/'(?:[^'\\\\]|\\\\.)*'/s", '/"(?:[^"\\\\]|\\\\.)*"/s'],
            static fn (array $m): string => str_repeat(' ', strlen($m[0])),
            $sql
        ) ?? $sql;

        $tokens = [];
        preg_match_all('/(?<![:\w]):([a-zA-Z_][a-zA-Z0-9_]*)/', $masked, $m, PREG_OFFSET_CAPTURE);
        // Only keep positions where the ':' is not part of a '::' cast.
        foreach ($m[0] as $index => [$token, $offset]) {
            $tokens[] = [$m[1][$index][0], $offset, strlen($token)];
        }
        return $tokens;
    }

    /** Unique placeholder names used by a statement. @return list<string> */
    private static function placeholderNames(string $sql): array
    {
        return array_values(array_unique(array_column(self::placeholderTokens($sql), 0)));
    }

    /**
     * Give every occurrence of a repeated named placeholder its own name.
     *
     * PDO rewrites ":q" to a separate positional "?" for each occurrence, so a query that
     * reuses a name ("name LIKE :q OR code LIKE :q") binds one slot and leaves the other
     * empty: MySQL then fails with SQLSTATE[HY093] "Invalid parameter number" while SQLite
     * tolerates it. Rewriting to ":q_2" makes the statement valid on every driver.
     *
     * @return array{0:string,1:list<string>} [rewritten sql, names in binding order]
     */
    private static function expandRepeatedPlaceholders(string $sql): array
    {
        $tokens = self::placeholderTokens($sql);
        $seen = [];
        $needsRewrite = false;
        foreach ($tokens as [$name]) {
            $seen[$name] = ($seen[$name] ?? 0) + 1;
            if ($seen[$name] > 1) {
                $needsRewrite = true;
            }
        }
        if (!$needsRewrite) {
            return [$sql, array_keys($seen)];
        }

        $names = [];
        $out = '';
        $cursor = 0;
        $counter = [];
        foreach ($tokens as [$name, $offset, $length]) {
            $counter[$name] = ($counter[$name] ?? 0) + 1;
            $alias = $counter[$name] === 1 ? $name : $name . '__' . $counter[$name];
            $out .= substr($sql, $cursor, $offset - $cursor) . ':' . $alias;
            $cursor = $offset + $length;
            $names[] = $alias;
        }
        $out .= substr($sql, $cursor);
        return [$out, $names];
    }

    /**
     * Bind parameters by name exactly once, using only the placeholders present in the SQL.
     *
     * This keeps queries portable: a statement may reference :q twice (PDO allows it on MySQL
     * and SQLite but not on every driver), and a stray key in the parameter array can no longer
     * produce the opaque "SQLSTATE[HY093]: Invalid parameter number".
     */
    private function bindParams(PDOStatement $stmt, string $sql, array $params, array $aliases = []): void
    {
        $hasNamed = str_contains($sql, ':');
        $names = $aliases !== [] ? $aliases : ($hasNamed ? self::placeholderNames($sql) : []);
        $provided = [];
        foreach ($params as $key => $value) {
            $provided[ltrim((string) $key, ':')] = $value;
        }
        if ($aliases !== []) {
            // Binding order follows the expanded aliases; values are looked up by base name.
            foreach ($names as $alias) {
                $base = str_contains($alias, '__') ? substr($alias, 0, (int) strrpos($alias, '__')) : $alias;
                if (!array_key_exists($base, $provided)) {
                    throw new RuntimeException('Query is missing value for placeholder :' . $base . ' — SQL: ' . $sql);
                }
                $provided[$alias] = $provided[$base];
            }
        }

        if ($names === []) {
            if ($params === []) {
                return; // nothing to bind
            }
            if (!str_contains($sql, '?')) {
                // The caller passed values but the SQL takes none (e.g. a fixed WHERE clause).
                $this->logDiagnostic('UNUSED PARAMS (no placeholders in SQL)', $sql, $params);
                return;
            }
            // Positional (?) parameters.
            $i = 1;
            foreach (array_values($params) as $value) {
                $stmt->bindValue($i++, $value, self::paramType($value));
            }
            return;
        }

        $missing = array_values(array_diff($names, array_keys($provided)));
        if ($missing !== []) {
            $this->logDiagnostic('MISSING PARAMS ' . implode(', ', $missing), $sql, $params);
            throw new RuntimeException(
                'Query is missing value(s) for placeholder(s): :' . implode(', :', $missing) . ' — SQL: ' . $sql
            );
        }
        $unused = array_values(array_diff(array_keys($provided), $names));
        if ($unused !== []) {
            // Extra keys are a bug in the caller. Dropping them keeps the query working, but
            // SAEE_STRICT_BIND=1 turns them into a hard error so tests catch them on any driver.
            $this->logDiagnostic('UNUSED PARAMS ' . implode(', ', $unused), $sql, $params);
            if (getenv('SAEE_STRICT_BIND') === '1') {
                throw new RuntimeException('Unused bind parameter(s) for :' . implode(', :', $unused) . ' — SQL: ' . $sql);
            }
        }
        foreach ($names as $name) {
            $value = $provided[$name];
            $stmt->bindValue(':' . $name, $value, self::paramType($value));
        }
    }

    private static function paramType(mixed $value): int
    {
        return match (true) {
            is_int($value)  => PDO::PARAM_INT,
            is_bool($value) => PDO::PARAM_BOOL,
            is_null($value) => PDO::PARAM_NULL,
            default         => PDO::PARAM_STR,
        };
    }

    private function logDiagnostic(string $label, string $sql, array $params): void
    {
        $dir = LOG_PATH;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'database-error.log', sprintf(
            "[%s] %s | SQL: %s | PARAMS: %s\n",
            date('c'),
            $label,
            $sql,
            json_encode(array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : '[object]', $params))
        ), FILE_APPEND);
    }

    private function prepare(string $sql, array $params): PDOStatement
    {
        $start = microtime(true);
        [$expandedSql, $aliases] = self::expandRepeatedPlaceholders($sql);
        try {
            $stmt = $this->pdo->prepare($expandedSql);
            $this->bindParams($stmt, $expandedSql, $params, $aliases);
            $stmt->execute();
        } catch (RuntimeException $e) {
            $this->logError($sql, $params, $e);
            throw $e;
        } catch (PDOException $e) {
            $this->logError($sql, $params, $e);
            throw new RuntimeException('Query failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        }
        if ($this->logQueries) {
            $this->queryLog[] = ['sql' => $sql, 'ms' => round((microtime(true) - $start) * 1000, 2)];
        }
        return $stmt;
    }

    private function logError(string $sql, array $params, Throwable $e): void
    {
        $dir = LOG_PATH;
        if (!is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }
        $line = sprintf(
            "[%s] SQL ERROR %s | SQL: %s | PARAMS: %s\n",
            date('c'),
            $e->getMessage(),
            $sql,
            json_encode(array_map(static fn ($v) => is_scalar($v) || $v === null ? $v : '[object]', $params))
        );
        @file_put_contents($dir . DIRECTORY_SEPARATOR . 'database-error.log', $line, FILE_APPEND);
    }

    // ------------------------------------------------------------------ reads
    public function select(string $sql, array $params = []): array
    {
        return $this->prepare($sql, $params)->fetchAll();
    }

    public function first(string $sql, array $params = []): ?array
    {
        $row = $this->prepare($sql, $params)->fetch();
        return $row === false ? null : $row;
    }

    public function value(string $sql, array $params = []): mixed
    {
        $row = $this->prepare($sql, $params)->fetch(PDO::FETCH_NUM);
        return $row === false ? null : $row[0];
    }

    public function column(string $sql, array $params = []): array
    {
        return $this->prepare($sql, $params)->fetchAll(PDO::FETCH_COLUMN);
    }

    // ----------------------------------------------------------------- writes
    public function statement(string $sql, array $params = []): PDOStatement
    {
        return $this->prepare($sql, $params);
    }

    public function execute(string $sql, array $params = []): int
    {
        return $this->prepare($sql, $params)->rowCount();
    }

    public function insert(string $table, array $data): int
    {
        $columns = array_keys($data);
        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $this->quoteIdentifier($table),
            implode(', ', array_map(fn ($c) => $this->quoteIdentifier($c), $columns)),
            implode(', ', array_map(fn ($c) => ':' . $c, $columns))
        );
        $this->prepare($sql, $data);
        return (int) $this->pdo->lastInsertId();
    }

    public function update(string $table, array $data, string $where, array $whereParams = []): int
    {
        $sets = [];
        foreach (array_keys($data) as $column) {
            $sets[] = $this->quoteIdentifier($column) . ' = :' . $column;
        }
        $sql = sprintf('UPDATE %s SET %s WHERE %s', $this->quoteIdentifier($table), implode(', ', $sets), $where);
        return $this->prepare($sql, array_merge($data, $whereParams))->rowCount();
    }

    public function delete(string $table, string $where, array $params = []): int
    {
        return $this->prepare(sprintf('DELETE FROM %s WHERE %s', $this->quoteIdentifier($table), $where), $params)->rowCount();
    }

    public function exists(string $table, string $where, array $params = []): bool
    {
        return (bool) $this->value(sprintf('SELECT 1 FROM %s WHERE %s LIMIT 1', $this->quoteIdentifier($table), $where), $params);
    }

    // ----------------------------------------------------------- transactions
    public function beginTransaction(): void
    {
        if ($this->transactionLevel === 0) {
            $this->pdo->beginTransaction();
        } else {
            $this->pdo->exec('SAVEPOINT trans' . $this->transactionLevel);
        }
        $this->transactionLevel++;
    }

    public function commit(): void
    {
        if ($this->transactionLevel === 0) {
            throw new RuntimeException('No active transaction to commit.');
        }
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->pdo->commit();
        } else {
            $this->pdo->exec('RELEASE SAVEPOINT trans' . $this->transactionLevel);
        }
    }

    public function rollBack(): void
    {
        if ($this->transactionLevel === 0) {
            return;
        }
        $this->transactionLevel--;
        if ($this->transactionLevel === 0) {
            $this->pdo->rollBack();
        } else {
            $this->pdo->exec('ROLLBACK TO SAVEPOINT trans' . $this->transactionLevel);
        }
    }

    public function transactionLevel(): int
    {
        return $this->transactionLevel;
    }

    /**
     * Execute a closure inside a database transaction. Nested calls create savepoints.
     */
    public function transaction(callable $callback): mixed
    {
        $this->beginTransaction();
        try {
            $result = $callback($this);
            $this->commit();
            return $result;
        } catch (Throwable $e) {
            $this->rollBack();
            throw $e;
        }
    }

    // -------------------------------------------------------------- utilities
    public function quoteIdentifier(string $identifier): string
    {
        $identifier = preg_replace('/[^A-Za-z0-9_]/', '', $identifier);
        return $this->driver === 'sqlite' ? '"' . $identifier . '"' : '`' . $identifier . '`';
    }

    /**
     * SQLite compatible NOW() fragment.
     */
    public function now(string $format = 'Y-m-d H:i:s'): string
    {
        return date($format);
    }

    /**
     * Portable "current timestamp" SQL expression.
     */
    public function currentTimestampSql(): string
    {
        return $this->driver === 'sqlite'
            ? "datetime('now','localtime')"
            : 'NOW()';
    }

    /**
     * Portable date difference expression: number of days between $column and today.
     */
    public function daysSinceSql(string $column): string
    {
        return $this->driver === 'sqlite'
            ? "CAST(julianday('now','localtime') - julianday($column) AS INTEGER)"
            : "DATEDIFF(CURDATE(), $column)";
    }

    /**
     * Portable year-month grouping expression.
     */
    public function monthExpression(string $column): string
    {
        return $this->driver === 'sqlite'
            ? "strftime('%Y-%m', $column)"
            : "DATE_FORMAT($column, '%Y-%m')";
    }

    /**
     * Portable CONCAT.
     */
    public function concat(string ...$parts): string
    {
        $parts = array_map(fn ($p) => str_starts_with($p, "'") || str_starts_with($p, '"') ? $p : $p, $parts);
        return $this->driver === 'sqlite' ? '(' . implode(' || ', $parts) . ')' : 'CONCAT(' . implode(', ', $parts) . ')';
    }

    public function queryLog(): array
    {
        return $this->queryLog;
    }

    public function tableExists(string $table): bool
    {
        try {
            if ($this->driver === 'sqlite') {
                return (bool) $this->value("SELECT 1 FROM sqlite_master WHERE type='table' AND name = ? LIMIT 1", [$table]);
            }
            return (bool) $this->value('SELECT 1 FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ? LIMIT 1', [$table]);
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Portable "insert or ignore" used for idempotent seeds / unique counters.
     */
    public function insertIgnore(string $table, array $data): bool
    {
        try {
            $this->insert($table, $data);
            return true;
        } catch (Throwable $e) {
            if (str_contains(strtolower($e->getMessage()), 'unique') || str_contains(strtolower($e->getMessage()), 'duplicate')) {
                return false;
            }
            throw $e;
        }
    }
}
