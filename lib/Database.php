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

    /** True when the tables' collation could not be applied to the connection. */
    private bool $collationMismatch = false;

    /** Charset of the tables, learned from the last information_schema probe. */
    private ?string $knownCharset = null;

    /** Per-request cache: charset -> the collation the server treats as its default. */
    private array $defaultCollations = [];

    /** Per-request cache of @@character_set_connection. */
    private ?string $connectionCharsetCache = null;

    /** Per-request cache: collation -> the character set the server says it belongs to. */
    private array $collationCharsets = [];

    /** Last collation that had to be stripped, for the diagnostics page. */
    private ?string $lastCollationReport = null;

    /** Per-request cache of column charsets, keyed table.column. */
    private array $columnCharsets = [];

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
                $this->applyConnectionCollation($c);
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
     * Character set a collation belongs to: "utf8mb4_unicode_ci" -> "utf8mb4".
     *
     * MySQL names every collation <charset>_<...>, so the charset is the text before the first
     * underscore. 'binary' is its own charset and must not be split.
     */
    private static function charsetOfCollation(string $collation): string
    {
        if ($collation === 'binary' || $collation === '') {
            return $collation === 'binary' ? 'binary' : '';
        }
        $charset = strstr($collation, '_', true);
        return $charset === false ? '' : $charset;
    }

    /** True for a syntactic collation name we are willing to interpolate into SQL. */
    private static function isCollationName(string $collation): bool
    {
        return preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1
            && self::charsetOfCollation($collation) !== '';
    }

    /**
     * Pin the connection charset/collation to what the tables actually use.
     *
     * The charset is always derived from the collation, never read from configuration
     * independently. `SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci` and
     * `SET NAMES latin1 COLLATE utf8mb4_unicode_ci` are the same statement to the calling code
     * but the second is rejected by MySQL with
     *
     *   1253 COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'
     *
     * and that is what broke the admin dashboard, the members list and the payout report while
     * leaving the public pages working.
     *
     * Nothing here is fatal. If the collation cannot be applied the connection keeps a charset
     * the server does accept, and the tables are converted to that collation so comparisons stay
     * legal (see repairCollations()).
     */
    private function applyConnectionCollation(array $c): void
    {
        $detected = $this->detectTableCollation();
        $configured = (string) ($c['collation'] ?? '');
        // The app charset is a hard requirement: it is what the content is written in. The
        // collation is what adapts to the server.
        $appCharset = (string) ($c['charset'] ?? '');
        $charset = $appCharset !== '' ? $appCharset : (self::charsetOfCollation($configured) ?: self::charsetOfCollation($detected));
        $plan = self::planConnectionCollation($detected, $configured, $appCharset, $this->collationForCharset($charset));

        $applied = null;
        foreach ($plan as [$planCharset, $planCollation]) {
            if ($this->execConnectionCharset($planCharset, $planCollation)) {
                $applied = [$planCharset, $planCollation];
                break;
            }
        }

        if ($applied === null) {
            // Nothing worked (no information_schema, no permission to SET NAMES). Leave the
            // server defaults alone; derived expressions are then pinned to the connection.
            $connection = $this->connectionCollation();
            $this->expressionCollation = $connection !== '' ? $connection : false;
            return;
        }

        [$appliedCharset, $appliedCollation] = $applied;
        // Belt and braces: a host can ignore SET NAMES entirely. If the connection still is not
        // in the application's charset, convert it explicitly and record that it happened.
        if ($appliedCharset !== '') {
            $actual = $this->connectionCharset(true);
            if ($actual !== '' && $actual !== $appliedCharset) {
                error_log('[db] SET NAMES ' . $appliedCharset . ' did not take effect (connection is '
                    . $actual . '); converting the connection explicitly');
                $this->execConnectionCharset($appliedCharset, '');
            }
        }
        $this->knownCharset = $appliedCharset;
        $this->expressionCollation = $appliedCollation !== '' ? $appliedCollation : $this->collationForCharset($appliedCharset);
        if ($this->expressionCollation === '') {
            $this->expressionCollation = false;
        }
        if ($detected !== '' && $detected !== $appliedCollation) {
            // Tables and connection disagree; comparisons would mix charsets. Convert the
            // tables to what the connection now uses so future requests are clean.
            $this->collationMismatch = true;
            error_log('[db] table collation ' . $detected . ' is not valid for the connection (charset '
                . $appliedCharset . '); pinned to ' . ($appliedCollation !== '' ? $appliedCollation : 'charset default')
                . ' and converting tables to match');
            if ($this->expressionCollation !== false) {
                $this->collationRepairAttempted = true;
                $this->repairCollations((string) $this->expressionCollation);
            }
        }
    }

    /**
     * True when the connection and the tables are in different character sets.
     *
     * This is the state that produces 1253 / 1267 pairs: the connection cannot express the
     * collation the tables use, and no COLLATE clause can bridge two charsets.
     */
    public function hasCharsetMismatch(): bool
    {
        if ($this->driver !== 'mysql') {
            return false;
        }
        $connection = $this->connectionCharset();
        $tables = $this->detectTableCollation();
        if ($connection === '' || $tables === '') {
            return false;
        }
        return self::charsetOfCollation($tables) !== $connection;
    }

    /**
     * Make the connection and the tables agree, converting the tables when that is safe.
     *
     * The application content is UTF-8 (Hindi/Marathi product names), so when the connection is
     * something narrower the tables are converted rather than the connection: dragging the
     * connection down to latin1 would corrupt every read. Returns the tables converted.
     *
     * Used by the emergency repair and by `collation:repair`, not on every request.
     *
     * @return list<string>
     */
    public function healCharsetMismatch(): array
    {
        if ($this->driver !== 'mysql') {
            return [];
        }
        $appCharset = (string) ($this->config['charset'] ?? 'utf8mb4');
        if (preg_match('/^[A-Za-z0-9_]+$/', $appCharset) !== 1) {
            $appCharset = 'utf8mb4';
        }
        $connection = $this->connectionCharset();
        if ($connection === '') {
            return [];
        }
        if ($connection !== $appCharset) {
            // Try to lift the connection first; it is free and fixes the common case.
            $this->execConnectionCharset($appCharset, '');
            $this->connectionCharsetCache = null;
            $this->expressionCollation = null;
            if ($this->connectionCharset() === $appCharset) {
                // Connection moved; the tables may already be right.
                if (!$this->hasCharsetMismatch()) {
                    return [];
                }
            } else {
                // The connection cannot leave its charset: convert the tables to match it.
                $appCharset = $this->connectionCharset();
            }
        }
        $target = $this->collationForCharset($appCharset);
        return $target === '' ? [] : $this->repairCollations($target);
    }

    /**
     * Ordered `SET NAMES` attempts for a connection, safest first.
     *
     * Pure so the rule can be tested without a server. Two invariants hold for every attempt:
     *
     *  1. the collation always belongs to the charset it is paired with (or is omitted), which is
     *     the fix for 1253 "COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'";
     *  2. the charset is the one the *application* is built for (utf8mb4), never whatever a table
     *     happens to use — the app stores Hindi and Marathi product names, so a latin1 connection
     *     would corrupt every read. Tables are converted to the app charset instead.
     *
     * @param string $detected         collation of the tables ('' when unknown)
     * @param string $configuredColl   DB_COLLATION ('' when unset)
     * @param string $appCharset       DB_CHARSET, the charset the application requires
     * @param string $charsetDefault   the server's default collation for $appCharset
     * @return list<array{0:string,1:string}> [charset, collation]; '' means "charset only"
     */
    private static function planConnectionCollation(
        string $detected,
        string $configuredColl,
        string $appCharset,
        string $charsetDefault
    ): array {
        // Which charset to run the connection in.
        $appCharset = preg_match('/^[A-Za-z0-9_]+$/', $appCharset) === 1 ? $appCharset : '';
        if ($appCharset === '') {
            $appCharset = self::charsetOfCollation($configuredColl) ?: self::charsetOfCollation($detected);
        }
        if ($appCharset === '') {
            return [];
        }

        $plan = [];
        // Best: the tables already agree with the app charset, so keep their exact collation.
        if (self::isCollationName($detected) && self::charsetOfCollation($detected) === $appCharset) {
            $plan[] = [$appCharset, $detected];
        }
        // Next: the configured collation, if it belongs to the app charset.
        if (self::isCollationName($configuredColl) && self::charsetOfCollation($configuredColl) === $appCharset) {
            $plan[] = [$appCharset, $configuredColl];
        }
        // Next: the server's own default collation for that charset — always legal.
        if (self::isCollationName($charsetDefault) && self::charsetOfCollation($charsetDefault) === $appCharset) {
            $plan[] = [$appCharset, $charsetDefault];
        }
        // Last resort: charset only, letting MySQL choose. Still legal, never 1253.
        $plan[] = [$appCharset, ''];

        // De-duplicate while preserving order.
        $seen = [];
        $unique = [];
        foreach ($plan as $attempt) {
            $key = $attempt[0] . '|' . $attempt[1];
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $unique[] = $attempt;
        }
        return $unique;
    }

    /**
     * Run `SET NAMES <charset> [COLLATE <collation>]`; returns false if MySQL rejected it.
     *
     * Both identifiers are validated before interpolation.
     */
    private function execConnectionCharset(string $charset, string $collation): bool
    {
        if (preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
            return false;
        }
        $sql = $collation !== ''
            ? sprintf('SET NAMES %s COLLATE %s', $charset, $collation)
            : sprintf('SET NAMES %s', $charset);
        try {
            $this->pdo->exec($sql);
            return true;
        } catch (Throwable $e) {
            error_log('[db] ' . $sql . ' was rejected: ' . $e->getMessage());
            return false;
        }
    }

    /**
     * Collation actually used by the application tables, read straight from the server.
     *
     * Whatever answers this decides the connection collation, because matching the tables is
     * the only thing that makes comparisons legal. Returns '' when it cannot be determined
     * (no tables yet, or no information_schema access).
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
            if (self::isCollationName($value)) {
                $this->knownCharset = self::charsetOfCollation($value);
                return $value;
            }
        } catch (Throwable) {
            // Fall back to the connection/configuration.
        }
        return '';
    }

    /**
     * The collation the server treats as the default for a character set.
     *
     * Asked of the server rather than assumed: `utf8mb4_general_ci` is the default on MariaDB
     * and on XAMPP's bundled MySQL, `utf8mb4_0900_ai_ci` on MySQL 8. Assuming
     * `utf8mb4_unicode_ci` is what made the app emit a collation the server did not accept.
     */
    private function collationForCharset(string $charset): string
    {
        if ($charset === '' || preg_match('/^[A-Za-z0-9_]+$/', $charset) !== 1) {
            return '';
        }
        if (isset($this->defaultCollations[$charset])) {
            return $this->defaultCollations[$charset];
        }
        // A collation the live connection already uses is by definition valid for it.
        $connection = $this->connectionCollation();
        if ($connection !== '' && self::charsetOfCollation($connection) === $charset) {
            return $this->defaultCollations[$charset] = $connection;
        }
        $found = '';
        try {
            $probe = $this->pdo->prepare(
                'SELECT COLLATION_NAME FROM information_schema.COLLATIONS
                  WHERE CHARACTER_SET_NAME = ? ORDER BY IS_DEFAULT DESC, COLLATION_NAME ASC LIMIT 1'
            );
            // A `?` placeholder is COLLATE-ineligible, so this comparison can never raise 1263.
            $probe->execute([$charset]);
            $found = (string) ($probe->fetchColumn() ?: '');
        } catch (Throwable) {
            $found = '';
        }
        if (self::isCollationName($found) && self::charsetOfCollation($found) === $charset) {
            return $this->defaultCollations[$charset] = $found;
        }
        // information_schema unavailable. unicode_ci exists on every utf8mb4 server this app
        // supports, and charset-only SET NAMES remains as the final fallback.
        return $this->defaultCollations[$charset] = ($charset === 'utf8mb4' ? 'utf8mb4_unicode_ci' : '');
    }

    /** True when the server accepts this collation for this character set. */
    private function collationIsValid(string $collation, string $charset): bool
    {
        if ($charset === '' || self::charsetOfCollation($collation) !== $charset) {
            return false;
        }
        try {
            $probe = $this->pdo->prepare(
                'SELECT COUNT(*) FROM information_schema.COLLATIONS
                  WHERE CHARACTER_SET_NAME = ? AND COLLATION_NAME = ?'
            );
            $probe->execute([$charset, $collation]);
            return (int) $probe->fetchColumn() > 0;
        } catch (Throwable) {
            return false;
        }
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
        // Fall back to the connection: its charset is authoritative, and a collation belonging
        // to a different charset (the 1253 case) must never be emitted into SQL.
        $connection = $this->connectionCollation();
        if ($connection !== '') {
            return $this->expressionCollation = $connection;
        }
        $configured = (string) ($this->config['collation'] ?? '');
        if (self::isCollationName($configured)) {
            $charset = $this->knownCharset ?? '';
            if ($charset === '' || self::charsetOfCollation($configured) === $charset) {
                return $this->expressionCollation = $configured;
            }
        }
        // The lookup failed (no information_schema access, restricted account, or the
        // tables are not created yet). Pin to the charset default, which is always legal.
        $default = $this->collationForCharset($this->knownCharset ?? (string) ($this->config['charset'] ?? ''));
        return $this->expressionCollation = ($default !== '' ? $default : false);
    }

    /** Collation of the live connection, read from the server. '' when unavailable. */
    private function connectionCollation(): string
    {
        try {
            $value = (string) ($this->pdo->query('SELECT @@collation_connection')->fetchColumn() ?: '');
        } catch (Throwable) {
            return '';
        }
        return self::isCollationName($value) ? $value : '';
    }

    /** Charset of the live connection, read from the server. '' when unavailable. */
    private function connectionCharset(bool $fresh = false): string
    {
        if ($this->connectionCharsetCache !== null && !$fresh) {
            return $this->connectionCharsetCache;
        }
        try {
            $value = (string) ($this->pdo->query('SELECT @@character_set_connection')->fetchColumn() ?: '');
        } catch (Throwable) {
            return $this->connectionCharsetCache = '';
        }
        return $this->connectionCharsetCache = (preg_match('/^[A-Za-z0-9_]+$/', $value) === 1 ? $value : '');
    }

    /**
     * Collation to pin a derived expression to, in the charset the expression really uses.
     *
     * `expressionCollation()` alone is not enough: `CONCAT(a, b)` returns the connection charset
     * while a column returns its own, so the pin has to be clamped to the charset of the thing
     * being compared — otherwise the server answers 1253 instead of the 1267 we were avoiding.
     */
    private function derivedCollation(?string $knownCharset = null): string|false
    {
        if ($this->driver !== 'mysql') {
            return false;
        }
        // The ONLY reason to pin an expression is to stop utf8mb4_unicode_ci and
        // utf8mb4_general_ci from mixing. On any other connection charset the pin is a
        // liability: MySQL answers 1253 "COLLATION 'utf8mb4_unicode_ci' is not valid for
        // CHARACTER SET 'latin1'" before the query even runs. No pin is strictly better than a
        // wrong pin, so a non-utf8mb4 connection gets its expressions left alone.
        $connectionCharset = $this->connectionCharset();
        if ($connectionCharset !== '' && !str_starts_with($connectionCharset, 'utf8')) {
            return false;
        }
        // The column being compared outranks the connection: a latin1 column compared with an
        // explicit utf8mb4 collation is rejected by the server even when everything else is
        // utf8mb4. This is the case that kept failing on payout_requests and orders.
        if ($knownCharset !== null && $knownCharset !== '' && !str_starts_with($knownCharset, 'utf8')) {
            return false;
        }
        $collation = $this->expressionCollation();
        if ($collation === false) {
            return false;
        }
        // Whatever we return must belong to the charset it will be applied in, otherwise the
        // server rejects the statement with 1253.
        $effective = $knownCharset !== null && $knownCharset !== '' ? $knownCharset : $connectionCharset;
        if ($effective !== '' && self::isCollationName($collation)
            && self::charsetOfCollation($collation) !== $effective) {
            return false;
        }
        return $collation;
    }

    /**
     * Append a COLLATE clause to a derived string expression (MySQL only).
     *
     * MySQL assigns function results the server default collation instead of the column's, so
     * `DATE_FORMAT(created_at, '%Y-%m') = :m` fails with 1267 on hosts where the two differ.
     * Anything that is a plain column reference is returned untouched.
     */
    public function withExpressionCollation(string $expression, ?string $columnCharset = null): string
    {
        return self::collateExpression($expression, $this->derivedCollation($columnCharset));
    }

    /**
     * Character set of a real column, read from the server. '' when it cannot be determined.
     *
     * This is the missing half of the 1253 story: the connection can be perfectly utf8mb4 while
     * the column being compared is latin1 (legacy data, or a table created before the charset was
     * pinned). Pinning such a comparison to utf8mb4_unicode_ci is rejected by the server, so the
     * column wins over the connection for deciding whether a pin is legal at all.
     */
    public function columnCharset(string $table, string $column): string
    {
        if ($this->driver !== 'mysql') {
            return '';
        }
        $key = $table . '.' . $column;
        if (isset($this->columnCharsets[$key])) {
            return $this->columnCharsets[$key];
        }
        $found = $this->metaLookup(
            'SELECT CHARACTER_SET_NAME FROM information_schema.COLUMNS
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ? LIMIT 1',
            [$table, $column]
        );
        return $this->columnCharsets[$key] = (preg_match('/^[A-Za-z0-9_]+$/', $found) === 1 ? $found : '');
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

    /**
     * Remove any COLLATE clause that belongs to a charset the connection cannot use.
     *
     * This is the last line of defence for
     *   1253 COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'
     * — the failure that kept coming back because the clause can be introduced from several
     * directions (a derived-expression pin, a repair target, a hand-written query). Rather than
     * trust every caller, the statement is inspected here, immediately before it is handed to
     * MySQL, and a collation of another character set is dropped. Dropping it is always safe:
     * the comparison then uses the connection collation, which is what the server would have
     * used anyway.
     *
     * The connection charset is read once per request, and SQL without the keyword costs a
     * single str_contains() check.
     */
    private function sanitiseCollateClauses(string $sql): string
    {
        if ($this->driver !== 'mysql' || stripos($sql, 'COLLATE') === false) {
            return $sql;
        }
        $stripped = [];
        $clean = preg_replace_callback(
            '/\s+COLLATE\s+([A-Za-z0-9_]+)/i',
            function (array $m) use (&$stripped): string {
                // Ask the server what this collation really belongs to. A charset derived from
                // the name is only a guess: a column can be in a charset the connection cannot
                // use even when the connection itself reports utf8mb4, and this question has a
                // single authoritative answer that costs nothing when the name is fine.
                $verdict = $this->collationCharsetOnServer($m[1]);
                if ($verdict === '') {
                    return $m[0];   // server does not know it: let MySQL report the real error
                }
                $connection = $this->connectionCharset();
                if ($connection === '' || $verdict === $connection) {
                    return $m[0];
                }
                $stripped[] = $m[1] . ' (belongs to ' . $verdict . ', connection is ' . $connection . ')';
                return '';
            },
            $sql
        );
        if ($stripped === []) {
            return $sql;
        }
        $this->explainStrippedCollations(array_unique($stripped));
        return (string) $clean;
    }


    /**
     * Run a metadata query against information_schema, tolerating how the name is resolved.
     *
     * MySQL accepts `information_schema.X`; some servers and proxies only resolve the bare
     * table name. A failed lookup must never be read as "nothing wrong" — that is exactly how a
     * foreign COLLATE slipped through and produced 1253 on the reporting install.
     *
     * @param list<string> $params
     */
    private function metaLookup(string $sql, array $params): string
    {
        foreach ([$sql, str_replace('information_schema.', '', $sql)] as $statement) {
            try {
                $probe = $this->pdo->prepare($statement);
                $probe->execute($params);
                $value = (string) ($probe->fetchColumn() ?: '');
            } catch (Throwable) {
                continue;
            }
            if ($value !== '') {
                return $value;
            }
        }
        return '';
    }

    /**
     * Ask information_schema which character set a collation belongs to.
     *
     * The name prefix is not reliable enough on its own, and this is the one fact that decides
     * whether a COLLATE clause is legal, so it comes from the server. Cached per request.
     */
    private function collationCharsetOnServer(string $collation): string
    {
        if (isset($this->collationCharsets[$collation])) {
            return $this->collationCharsets[$collation];
        }
        $found = $this->metaLookup(
            'SELECT CHARACTER_SET_NAME FROM information_schema.COLLATIONS WHERE COLLATION_NAME = ? LIMIT 1',
            [$collation]
        );
        // Still unknown: the name prefix is exact for every collation this app can emit.
        if ($found === '') {
            $found = self::charsetOfCollation($collation);
        }
        return $this->collationCharsets[$collation] = $found;
    }

    /**
     * Record that a collation was removed, and why — on the page in debug, in the log always.
     *
     * Silently repairing is what made this hard to diagnose: the page worked, so nobody looked,
     * and the reason (a column in a charset the connection cannot use) stayed hidden. Reporting
     * it turns a mystery into a one-line diagnosis.
     */
    private function explainStrippedCollations(array $details): void
    {
        $summary = 'removed COLLATE ' . implode('; ', $details);
        error_log('[db] ' . $summary);
        $this->logDiagnostic('STRIPPED ' . $summary, '', []);
        $this->lastCollationReport = $summary;
    }

    private function prepare(string $sql, array $params, bool $allowCollationRetry = true): PDOStatement
    {
        $start = microtime(true);
        $sql = $this->sanitiseCollateClauses($sql);
        [$expandedSql, $aliases] = self::expandRepeatedPlaceholders($sql);
        try {
            $stmt = $this->pdo->prepare($expandedSql);
            $this->bindParams($stmt, $expandedSql, $params, $aliases);
            $stmt->execute();
        } catch (PDOException $e) {
            // MUST come before the RuntimeException branch: in PHP 8 PDOException extends
            // RuntimeException, so catching the parent first would swallow every database error
            // and rethrow it, making the collation recovery below unreachable. That is precisely
            // why 1253 kept reaching the page after the recovery code had been added.
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
                    // Convert to the collation the *connection* uses, not to whatever the tables
                    // happen to say: if they disagree, converting tables to their own collation
                    // would leave the mismatch in place.
                    $target = $this->connectionCollation();
                    if ($this->repairCollations($target !== '' ? $target : null) !== []) {
                        return $this->prepare($sql, $params, false);
                    }
                }
            }
            // Error 1253 = "COLLATION 'x' is not valid for CHARACTER SET 'y'". Whatever produced
            // it (a pin, a legacy latin1 column, a hand-written query, a server that reports a
            // different charset than it enforces), the remedy is always the same and always safe:
            // remove the COLLATE clause and run the identical query again. The comparison then
            // uses the connection collation, which is what the server would have used anyway.
            if ($allowCollationRetry
                && $this->driver === 'mysql'
                && ((int) ($e->errorInfo[1] ?? 0) === 1253 || str_contains($e->getMessage(), 'is not valid for CHARACTER SET'))
                && stripos($sql, 'COLLATE') !== false) {
                $this->logError($sql, $params, $e);
                $this->explainStrippedCollations(['all clauses (server said: ' . $e->getMessage() . ')']);
                $withoutPins = (string) preg_replace('/\s+COLLATE\s+[A-Za-z0-9_]+/i', '', $sql);
                if ($withoutPins !== $sql) {
                    return $this->prepare($withoutPins, $params, false);
                }
            }
            $this->logError($sql, $params, $e);
            throw new RuntimeException('Query failed: ' . $e->getMessage(), (int) $e->getCode(), $e);
        } catch (RuntimeException $e) {
            $this->logError($sql, $params, $e);
            throw $e;
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
        if ($this->driver === 'sqlite') {
            return "strftime('%Y-%m', $column)";
        }
        return $this->withExpressionCollation("DATE_FORMAT($column, '%Y-%m')", $this->charsetOfColumn($column));
    }

    /**
     * Charset of a `table.column` or `column` reference, for deciding whether a COLLATE pin is
     * legal. The table is taken from a `t.col` prefix when there is one, otherwise every table
     * that has the column is checked and a disagreement returns '' (no pin).
     */
    private function charsetOfColumn(string $reference): string
    {
        if ($this->driver !== 'mysql') {
            return '';
        }
        $reference = trim($reference, " \t`");
        $table = '';
        $column = $reference;
        if (str_contains($reference, '.')) {
            [$table, $column] = explode('.', $reference, 2);
            $table = trim($table, " `");
        }
        $column = trim($column, " `");
        if ($column === '' || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
            return '';
        }
        if ($table !== '') {
            return $this->columnCharset($table, $column);
        }
        // Unqualified: the tables are probed one by one and must agree, otherwise no pin.
        $found = [];
        try {
            $tables = $this->pdo->query(
                "SELECT TABLE_NAME FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE'"
            )->fetchAll(PDO::FETCH_COLUMN) ?: [];
        } catch (Throwable) {
            $tables = [];
        }
        if ($tables === []) {
            return '';
        }
        foreach ($tables as $table) {
            $charset = $this->columnCharset((string) $table, $column);
            if ($charset !== '') {
                $found[$charset] = true;
            }
        }
        return count($found) === 1 ? (string) array_key_first($found) : '';
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
        // Callers may pass either a collation (utf8mb4_unicode_ci) or a bare charset (utf8mb4),
        // because a charset alone is all that is needed to convert a table.
        if ($collation !== '' && !str_contains($collation, '_') && preg_match('/^[A-Za-z0-9_]+$/', $collation) === 1) {
            $charset = $collation;
            $collation = $this->collationForCharset($charset);
        }
        if ($collation === '' || !self::isCollationName($collation)) {
            return [];
        }
        $charset = self::charsetOfCollation($collation);
        if ($charset === '') {
            return [];
        }
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
            // A table's own collation is not the whole story: a column can carry an older
            // charset from before the schema was pinned (orders.created_at, payout_requests.
            // requested_at were latin1 on the reporting install). Converting the table fixes
            // those too, so check the columns before deciding there is nothing to do.
            $needsWork = $current !== $collation;
            if (!$needsWork) {
                try {
                    $colProbe = $this->pdo->prepare(
                        'SELECT COUNT(*) FROM information_schema.COLUMNS
                          WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :t
                            AND CHARACTER_SET_NAME IS NOT NULL AND CHARACTER_SET_NAME <> :cs'
                    );
                    $colProbe->execute(['t' => $table, 'cs' => $charset]);
                    $needsWork = (int) $colProbe->fetchColumn() > 0;
                } catch (Throwable) {
                    $needsWork = false;
                }
            }
            if (!$needsWork) {
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
                // COLLATE guard still keeps comparisons working. Log it so the operator can
                // see which table still disagrees instead of guessing.
                error_log('[db] could not convert `' . $table . '` to ' . $charset . '/' . $collation . ': ' . $e->getMessage());
            }
        }
        if ($repaired !== []) {
            // The cached answers describe the tables as they were a moment ago.
            $this->expressionCollation = null;
            $this->knownCharset = $charset;
            $this->collationMismatch = false;
        }
        return $repaired;
    }

    /**
     * Portable date expression (Y-m-d), pinned to the column collation on MySQL for the same
     * reason as monthExpression().
     */
    public function dateExpression(string $column): string
    {
        if ($this->driver === 'sqlite') {
            return "strftime('%Y-%m-%d', $column)";
        }
        return $this->withExpressionCollation("DATE($column)", $this->charsetOfColumn($column));
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
