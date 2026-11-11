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

    /**
     * Collation injected after the comparison operators of derived (function-call) string
     * expressions, e.g. DATE_FORMAT(col, '%Y-%m') COLLATE utf8mb4_unicode_ci = :m.
     *
     * MySQL gives every function result the coercibility COERCIBLE and the *server* default
     * collation. When the server default and the column collation differ (very common on
     * shared hosting, where the tables are utf8mb4_unicode_ci but the server/connection
     * defaults to utf8mb4_general_ci), any comparison between the two raises error 1267
     * "Illegal mix of collations". Pinning the expression to the app collation fixes it.
     * Empty means "not yet resolved" and false means "not applicable" (SQLite).
     */
    private string|false|null $expressionCollation = null;

    /** True once the collation repair has been attempted for this connection. */
    private bool $collationRepairAttempted = false;

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
                $this->pdo = $this->connectMysql($c, $options);
                // The DSN charset only sets the character set; MySQL then applies that charset's
                // *default* collation (utf8mb4_general_ci on 5.7/MariaDB). Bound parameters and
                // function results inherit that, so comparing them to a utf8mb4_unicode_ci column
                // fails with 1267 "Illegal mix of collations".
                //
                // The connection must be pinned to the collation the TABLE actually uses — not to
                // whatever the config says. Forcing unicode_ci on a database whose tables are
                // general_ci would break every comparison instead of fixing them, so the table is
                // inspected first and the configuration is only a fallback.
                $collation = $this->detectTableCollation()
                    ?: (string) ($c['collation'] ?? '');
                if (preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1) {
                    try {
                        $this->pdo->exec(sprintf(
                            'SET NAMES %s COLLATE %s',
                            (string) ($c['charset'] ?? 'utf8mb4'),
                            $collation
                        ));
                        $this->expressionCollation = $collation;
                    } catch (Throwable $collationError) {
                        // A collation the server does not know must never fail the connection:
                        // the server default is still usable and the query layer pins expressions.
                        error_log('[db] could not apply connection collation ' . $collation . ': ' . $collationError->getMessage());
                    }
                }
                $this->pdo->exec("SET sql_mode='STRICT_TRANS_TABLES,NO_ZERO_DATE,NO_ENGINE_SUBSTITUTION'");
                $this->pdo->exec("SET time_zone = '+05:30'");
            }
        } catch (PDOException $e) {
            throw new RuntimeException(self::explainConnectionError($e, $this->driver), 0, $e);
        }
    }

    /**
     * Open the MySQL connection, tolerating the two failure modes that only appear on live
     * hosting and are invisible on a local XAMPP box.
     *
     * 1. "Operation not permitted" ([2002]). Shared hosts commonly run MySQL on a unix socket
     *    while the app connects with host=127.0.0.1, so the client tries a TCP socket the host
     *    forbids. It is also the error a per-account connection limit produces. Retrying is
     *    safe: the failure happens before any query runs, so no work is repeated.
     * 2. The whole failure is transient — one failed handshake should not blow up a page that
     *    will work a second later, which is why a single failed attempt surfaces as an error
     *    on click and then disappears on refresh.
     *
     * Config keys (all optional): attempts (default 3), connect_timeout (default 5 seconds)
     * and the SAEE_DB_ATTEMPTS / SAEE_DB_TIMEOUT env vars.
     */
    private function connectMysql(array $c, array $options): PDO
    {
        $charset = (string) ($c['charset'] ?? 'utf8mb4');
        $database = (string) ($c['database'] ?? '');
        $options[PDO::ATTR_TIMEOUT] = (int) ($c['connect_timeout']
            ?? (getenv('SAEE_DB_TIMEOUT') ?: 5));

        // Candidate connection strings, tried in order. Shared hosting often only permits one
        // of them: "Operation not permitted" ([2002]) is what you get when TCP is blocked but a
        // unix socket would work.
        $targets = [];
        $socket = trim((string) ($c['socket'] ?? ''));
        if ($socket !== '') {
            $targets[] = sprintf('mysql:unix_socket=%s;dbname=%s;charset=%s', $socket, $database, $charset);
        }
        $hosts = [(string) $c['host']];
        // 127.0.0.1 and localhost are not interchangeable on MySQL: "localhost" selects the
        // unix socket, an IP forces TCP. Trying both covers a host that only allows one.
        if (in_array(strtolower((string) $c['host']), ['localhost', '127.0.0.1', '::1'], true)) {
            $hosts[] = ((string) $c['host'] === 'localhost') ? '127.0.0.1' : 'localhost';
        }
        foreach (array_unique($hosts) as $host) {
            $targets[] = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, (int) $c['port'], $database, $charset);
        }

        $attempts = max(1, (int) ($c['attempts'] ?? (getenv('SAEE_DB_ATTEMPTS') ?: 3)));
        $firstError = null;

        foreach ($targets as $targetIndex => $dsn) {
            for ($attempt = 1; $attempt <= $attempts; $attempt++) {
                try {
                    $pdo = new PDO($dsn, $c['username'], $c['password'], $options);
                    if ($targetIndex > 0) {
                        error_log('[db] connected through fallback target #' . $targetIndex . ' (the configured one was unreachable)');
                    }
                    return $pdo;
                } catch (PDOException $e) {
                    $firstError ??= $e;
                    // 1049 = unknown database. A fresh XAMPP user usually has not created it
                    // yet, so create it once (utf8mb4) and reconnect instead of failing.
                    if ((int) $e->getCode() === 1049 || str_contains($e->getMessage(), 'Unknown database')) {
                        $serverDsn = (string) preg_replace('/;dbname=[^;]*/', '', $dsn);
                        $server = new PDO($serverDsn, $c['username'], $c['password'], $options);
                        $name = str_replace('`', '', $database);
                        $server->exec('CREATE DATABASE IF NOT EXISTS `' . $name . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                        return new PDO($dsn, $c['username'], $c['password'], $options);
                    }
                    // Only connection-level failures are worth retrying; a bad password
                    // (1045) or an unknown database will fail just as fast next time.
                    $retryable = (int) $e->getCode() === 2002
                        || (int) $e->getCode() === 2006
                        || str_contains($e->getMessage(), 'Operation not permitted')
                        || str_contains($e->getMessage(), 'Too many connections')
                        || str_contains($e->getMessage(), 'Connection refused');
                    if (!$retryable) {
                        throw $e;
                    }
                    if ($attempt < $attempts) {
                        usleep(min(200000, 40000 * $attempt) * ($attempt > 1 ? 2 : 1));
                    }
                }
            }
        }

        throw $firstError ?? new PDOException('MySQL connection failed for unknown reasons.');
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
            // "Operation not permitted" is NOT "MySQL is down" — on shared Linux hosting it
            // almost always means TCP to the database is blocked and only a unix socket is
            // allowed, so it must not be described as a stopped service.
            str_contains($raw, 'Operation not permitted') => 'The host refused the TCP connection to MySQL. On shared hosting MySQL usually listens on a unix socket only — set DB_SOCKET in .env (e.g. /var/lib/mysql/mysql.sock) or ask your host for the socket path and the database host name. This is also what a full connection limit looks like, so retrying is often enough.',
            (int) $e->getCode() === 2002 || str_contains($raw, 'No such file or directory') || str_contains($raw, 'refused') => 'MySQL is not reachable on this host/port. Locally this means MySQL is not running or is on another port (usually 3306 — start it in the XAMPP control panel); on shared hosting check the database host name given in your control panel.',
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
     * Collation actually used by the application tables, read straight from the server.
     *
     * Whatever answers this decides the connection collation, because matching the tables is
     * the only thing that makes comparisons legal. Returns '' when it cannot be determined.
     */
    private function detectTableCollation(): string
    {
        if ($this->driver !== 'mysql') {
            return '';
        }
        try {
            // Most common collation among the real tables; ties resolve to the smaller name so
            // the result is stable between requests.
            $sql = "SELECT TABLE_COLLATION AS c, COUNT(*) AS n
                      FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE()
                       AND TABLE_TYPE = 'BASE TABLE'
                       AND TABLE_COLLATION IS NOT NULL
                     GROUP BY TABLE_COLLATION
                     ORDER BY n DESC, c ASC
                     LIMIT 1";
            $value = (string) ($this->pdo->query($sql)->fetchColumn() ?: '');
            if (preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 && str_starts_with($value, 'utf8')) {
                return $value;
            }
        } catch (Throwable) {
            // Fall back to configuration.
        }
        return '';
    }

    /**
     * Collation that derived string expressions are pinned to.
     *
     * Order of preference: the configured connection collation (config/database.php, which is
     * applied with SET NAMES ... COLLATE), then the actual collation of the `members` table,
     * then utf8mb4_unicode_ci as a safe default. Returns false on SQLite.
     */
    public function expressionCollation(): string|false
    {
        if ($this->expressionCollation !== null) {
            return $this->expressionCollation;
        }
        $this->expressionCollation = false;
        if ($this->driver !== 'mysql') {
            return false;
        }
        // What the tables actually use always wins over configuration.
        $detected = $this->detectTableCollation();
        if ($detected !== '') {
            return $this->expressionCollation = $detected;
        }
        $configured = (string) ($this->config['collation'] ?? '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $configured) === 1 && str_starts_with($configured, 'utf8')) {
            return $this->expressionCollation = $configured;
        }
        try {
            $table = $this->pdo->prepare(
                'SELECT TABLE_COLLATION FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t LIMIT 1'
            );
            $table->execute(['t' => 'members']);
            $collation = (string) ($table->fetchColumn() ?: '');
            if (preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
                // `members` missing or no collation recorded: fall back to the database default.
                $collation = (string) ($this->pdo->query('SELECT @@collation_database')->fetchColumn() ?: '');
            }
            if (preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1 && str_starts_with($collation, 'utf8')) {
                $this->expressionCollation = $collation;
            }
        } catch (Throwable) {
            // Fall through to the app-wide collation below.
        }
        if ($this->expressionCollation === false) {
            // The lookup failed (no information_schema access, restricted account, or the
            // tables are not created yet). Still pin the expression: an explicit COLLATE
            // outranks a parameter's coercible collation, so any utf8mb4 collation resolves
            // 1267. This app's schema is created with utf8mb4_unicode_ci throughout.
            $this->expressionCollation = 'utf8mb4_unicode_ci';
        }
        return $this->expressionCollation;
    }

    /**
     * Append a COLLATE clause to a derived string expression (MySQL only).
     *
     * MySQL assigns function results the server default collation instead of the column's, so
     * `DATE_FORMAT(created_at, '%Y-%m') = :m` fails with 1267 on hosts where the two differ.
     * Anything that is a plain column reference is returned untouched.
     */
    public function withExpressionCollation(string $expression): string
    {
        return self::collateExpression($expression, $this->expressionCollation());
    }

    /**
     * Pure helper behind withExpressionCollation(): pin a derived expression to a collation.
     *
     * A plain column reference already carries its own collation, and an expression that
     * states one explicitly must not be doubled up, so both are returned untouched.
     */
    public static function collateExpression(string $expression, string|false $collation): string
    {
        if ($collation === false
            || $collation === ''
            || !str_contains($expression, '(')
            || preg_match('/\bCOLLATE\b/i', $expression) === 1
            || preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
            return $expression;
        }
        return $expression . ' COLLATE ' . $collation;
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

    private function prepare(string $sql, array $params, bool $allowCollationRetry = true): PDOStatement
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
            // Error 1267 = "Illegal mix of collations". A database whose tables were created
            // under different collations cannot compare its own strings. Rather than fail the
            // page, normalise the collations once and retry the same query exactly one time.
            if ($allowCollationRetry
                && $this->transactionLevel === 0   // ALTER TABLE would implicitly commit an open transaction
                && $this->driver === 'mysql'
                && ((int) ($e->errorInfo[1] ?? 0) === 1267 || str_contains($e->getMessage(), 'Illegal mix of collations'))) {
                $this->logError($sql, $params, $e);
                // Latch the repair so a failing statement can never start a second one: the
                // per-table ALTERs themselves throw on tables the account cannot modify, and
                // without the latch each of those would call back into this branch.
                if (!$this->collationRepairAttempted) {
                    $this->collationRepairAttempted = true;
                    if ($this->repairCollations() !== []) {
                        return $this->prepare($sql, $params, false);
                    }
                }
            }
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
     *
     * MySQL only: the result is pinned to the column collation — see expressionCollation().
     * Without it, comparing or grouping this value against a bound parameter throws
     * "1267 Illegal mix of collations" whenever the server default differs from the table's.
     */
    public function monthExpression(string $column): string
    {
        return $this->driver === 'sqlite'
            ? "strftime('%Y-%m', $column)"
            : $this->withExpressionCollation("DATE_FORMAT($column, '%Y-%m')");
    }

    /**
     * Normalise every table to one charset/collation so string comparisons can never raise
     * "1267 Illegal mix of collations" again.
     *
     * A shared-hosting database is often a mix: tables created by an installer at one point are
     * utf8mb4_unicode_ci while tables created later (or by phpMyAdmin/another script) picked up
     * the server default, usually utf8mb4_general_ci. MySQL refuses to compare columns with
     * different collations, so such a mix breaks queries that join or compare user data.
     *
     * @return list<string> tables that were altered
     */
    public function repairCollations(?string $collation = null): array
    {
        if ($this->driver !== 'mysql') {
            return [];
        }
        $collation = $collation ?: (string) ($this->expressionCollation() ?: '');
        if (preg_match('/^[A-Za-z0-9_]+$/', $collation) !== 1) {
            return [];
        }
        $charset = strstr($collation, '_', true) ?: 'utf8mb4';
        $repaired = [];
        // Deliberately the raw PDO API, not $this->select(): going through the query layer would
        // re-enter the 1267 handler that calls this method.
        try {
            $tables = $this->pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) {
            return [];
        }
        foreach ($tables as $table) {
            $table = str_replace('`', '', (string) $table);
            try {
                $probe = $this->pdo->prepare(
                    'SELECT TABLE_COLLATION FROM information_schema.TABLES
                      WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t'
                );
                $probe->execute(['t' => $table]);
                $current = (string) ($probe->fetchColumn() ?: '');
            } catch (Throwable) {
                continue;
            }
            if ($current === $collation) {
                continue;
            }
            try {
                $this->pdo->exec(sprintf(
                    'ALTER TABLE `%s` CONVERT TO CHARACTER SET %s COLLATE %s',
                    $table,
                    $charset,
                    $collation
                ));
                $repaired[] = $table;
            } catch (Throwable) {
                // A view or a table the account cannot alter is not fatal: the query-level
                // COLLATE guard still keeps comparisons working.
            }
        }
        return $repaired;
    }

    /**
     * Portable date expression (Y-m-d), pinned to the column collation on MySQL for the same
     * reason as monthExpression().
     */
    public function dateExpression(string $column): string
    {
        return $this->driver === 'sqlite'
            ? "strftime('%Y-%m-%d', $column)"
            : $this->withExpressionCollation("DATE($column)");
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
