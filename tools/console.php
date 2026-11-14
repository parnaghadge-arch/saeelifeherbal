<?php
/**
 * Minimal CLI console used for installation, seeding and maintenance tasks.
 *
 * Usage:
 *   php tools/console.php install [--admin-email=.. --admin-pass=.. --fresh]
 *   php tools/console.php demo           (development seed data)
 *   php tools/console.php schema:mysql   (write database/schema.sql)
 *   php tools/console.php schema:sqlite
 *   php tools/console.php royalty:run
 *   php tools/console.php commissions:process
 *   php tools/console.php payouts:auto   (create auto payouts from earnings, cron)
 *   php tools/console.php backups:run
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use Saee\Lib\Database;
use Saee\Lib\Installer;

$argv = $_SERVER['argv'] ?? [];
$command = $argv[1] ?? 'help';
$opts = [];
foreach (array_slice($argv, 2) as $arg) {
    if (preg_match('/^--([a-z0-9-]+)(?:=(.*))?$/', $arg, $m)) {
        $opts[$m[1]] = $m[2] ?? '1';
    }
}

/**
 * Preflight: tell the operator exactly what is wrong before touching the schema.
 * A silent "Something went wrong" on a fresh Windows/XAMPP checkout is the most
 * common support question, so every failure below is spelled out.
 */
$dbConfig = (array) \Saee\Lib\Config::get('database.db');
$driver = (string) ($dbConfig['driver'] ?? 'sqlite');
$commands = [
    'install', 'upgrade', 'demo', 'doctor', 'selftest',
    'schema:mysql', 'schema:sqlite',
    'collation:repair', 'collation:heal',
    'logs', 'commissions:process', 'royalty:run', 'backups:run', 'sitemap', 'seed-payouts', 'warn:scan',
    'payouts:auto', 'mlm:drop-commission-rules', 'production:reset',
];

if (!in_array($command, $commands, true)) {
    echo "Saee Life Herbal console\n\n";
    echo "Usage: php tools/console.php <command> [options]\n\n";
    echo "  install              create all tables, settings, roles and the first admin\n";
    echo "      --admin-user=admin --admin-pass='Admin@12345'\n";
    echo "      --admin-email=a@b.com --admin-name='Name' --no-fresh\n";
    echo "  upgrade              create tables added by newer versions (safe to re-run)\n";
    echo "  collation:repair     convert every table to one collation (fixes SQLSTATE 1267)\n";
    echo "  collation:heal       fix a connection/tables charset mismatch (fixes 1253)\n";
    echo "  logs                  show recent PHP errors (use a code from the error page)\n";
    echo "      --tail=40 --grep=ERR-XXXXXXXX --clear\n";
    echo "  demo                 seed demo catalogue, members, orders and commissions\n";
    echo "  production:reset     WIPE everything and rebuild a clean production database\n";
    echo "      keeps settings/products/pages, seeds no demo partners or orders,\n";
    echo "      creates the SAEE00001-00002-00003 company chain (fallback = SAEE00003)\n";
    echo "      --admin-user=admin --admin-pass='Admin@12345' --yes\n";
    echo "  schema:mysql         write database/schema.sql from the schema definition\n";
    echo "  schema:sqlite        write database/schema.sqlite.sql\n";
    echo "  commissions:process  release matured commission holds (cron)\n";
    echo "  mlm:drop-commission-rules  drop the retired mlm_commission_rules table\n";
    echo "  payouts:auto         create auto payouts from eligible earnings (cron)\n";
    echo "  royalty:run          evaluate royalty achievements (cron)\n";
    echo "  backups:run          write a database backup into storage/backups (cron)\n";
    echo "  sitemap              regenerate sitemap.xml (cron)\n";
    echo "  doctor               check PHP version, extensions, folders and database connectivity\n";
    echo "  warn:scan            list template array reads that lack a ?? fallback\n";
    echo "      --path=admin (default) or --path=member\n";
    echo "  selftest             run a quick query-binding smoke test against the database\n\n";
    echo "Active database driver: " . $driver . PHP_EOL;
    exit(0);
}

if ($command === 'doctor') {
    $ok = true;
    $line = static function (bool $pass, string $label, string $detail = ''): void {
        echo ($pass ? '  [ OK ]   ' : '  [FAIL]  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
    };

    echo "Saee Life Herbal — environment check" . PHP_EOL . PHP_EOL;

    $phpOk = PHP_VERSION_ID >= 80100;
    $line($phpOk, 'PHP version', PHP_VERSION . ($phpOk ? '' : ' (8.1+ required — upgrade XAMPP/PHP)'));

    foreach (['pdo' => 'PDO', 'mbstring' => 'mbstring', 'openssl' => 'OpenSSL', 'fileinfo' => 'fileinfo', 'json' => 'JSON', 'gd' => 'GD (image resize)', 'zip' => 'Zip (backups)'] as $ext => $label) {
        $loaded = extension_loaded($ext);
        $line($loaded, 'Extension ' . $label, $loaded ? '' : 'enable extension=' . $ext . ' in php.ini');
        $ok = $ok && ($loaded || in_array($ext, ['gd', 'zip'], true));
    }

    $drivers = \PDO::getAvailableDrivers();
    $line(in_array($driver, $drivers, true), 'PDO driver "' . $driver . '"', 'available: ' . implode(', ', $drivers));
    $ok = $ok && in_array($driver, $drivers, true);

    echo PHP_EOL . "Database target: " . $driver . PHP_EOL;
    if ($driver === 'mysql') {
        echo '  host=' . ($dbConfig['host'] ?? '') . '  port=' . ($dbConfig['port'] ?? '') . '  database=' . ($dbConfig['database'] ?? '') . '  user=' . ($dbConfig['username'] ?? '') . PHP_EOL;
    } else {
        echo '  file=' . ($dbConfig['sqlite_path'] ?? '') . PHP_EOL;
    }

    try {
        $probe = Database::instance();
        $probe->value('SELECT 1');
        $version = 'connected';
        try {
            $version = (string) ($probe->driver() === 'mysql' ? $probe->value('SELECT VERSION()') : 'SQLite ' . \SQLite3::version()['versionString']);
        } catch (Throwable) {
        }
        $line(true, 'Database connection', $version);
    } catch (Throwable $e) {
        $line(false, 'Database connection', $e->getMessage());
        echo PHP_EOL . '  Fix the connection first (MySQL running? database created? .env correct?).' . PHP_EOL;
        $ok = false;
    }

    echo PHP_EOL . "Writable folders:" . PHP_EOL;
    foreach (['storage', 'storage/db', 'storage/logs', 'storage/sessions', 'storage/private', 'storage/backups', 'uploads', 'database'] as $dir) {
        $path = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $dir);
        $exists = is_dir($path);
        $writable = $exists && is_writable($path);
        $line($writable, $dir, $exists ? ($writable ? '' : 'not writable — give the web server write permission') : 'missing — create it');
        $ok = $ok && $writable;
    }

    echo PHP_EOL . 'Front-end assets:' . PHP_EOL;
    $assetMap = [
        'assets/css/app.css' => 'Design system',
        'assets/js/app.js' => 'Public JavaScript',
        'assets/vendor/bootstrap/bootstrap.min.css' => 'Bootstrap CSS',
        'assets/vendor/bootstrap/bootstrap.bundle.min.js' => 'Bootstrap JS',
        'assets/vendor/fontawesome/css/all.min.css' => 'Font Awesome CSS',
        'assets/vendor/fontawesome/webfonts/fa-solid-900.woff2' => 'Font Awesome icons',
        'assets/vendor/jquery/jquery.min.js' => 'jQuery',
        'assets/vendor/tinymce/tinymce/tinymce.min.js' => 'TinyMCE editor',
        'assets/images/logo-square.png' => 'Logo',
    ];
    foreach ($assetMap as $asset => $label) {
        $path = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $asset);
        $line(is_file($path), $asset, is_file($path) ? $label : 'MISSING — the UI will render unstyled; re-clone or restore assets/vendor');
        $ok = $ok && is_file($path);
    }

    echo PHP_EOL . 'Routing (extension-less URLs, sub-directory installs):' . PHP_EOL;
    foreach (['config/routes.php' => 'Route map', 'includes/router.php' => 'URL resolver', '.htaccess' => 'Apache rewrite rules'] as $file => $label) {
        $path = APP_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $file);
        $line(is_file($path), $file, is_file($path) ? $label : 'MISSING — extension-less URLs will 404');
        $ok = $ok && is_file($path);
    }
    $htaccess = @file_get_contents(APP_ROOT . '/.htaccess') ?: '';
    $line(str_contains($htaccess, 'RewriteRule ^ index.php'), 'Rewrite rule to index.php', 'present in .htaccess');
    if (!str_contains($htaccess, 'RewriteRule ^ index.php')) {
        $ok = false;
    }
    $_SERVER['DOCUMENT_ROOT'] = $_SERVER['DOCUMENT_ROOT'] ?? APP_ROOT;
    require_once APP_ROOT . '/includes/router.php';
    echo '  Base path detected: "' . saee_base_path() . '"' . PHP_EOL;
    foreach (['product-categories', 'about-us', 'our-products', 'blogs', 'login'] as $slug) {
        $route = saee_resolve($slug);
        $line($route !== null && is_file($route['file']), '/' . $slug, $route ? basename($route['file']) : 'no route');
        $ok = $ok && $route !== null && is_file($route['file']);
    }

    echo PHP_EOL . 'Config: APP_ENV=' . APP_ENV . '  APP_DEBUG=' . (APP_DEBUG ? '1' : '0')
        . '  APP_URL=' . ((string) \Saee\Lib\Config::get('app.url', '') ?: '(auto)') . PHP_EOL;
    echo '.env file: ' . (is_file(APP_ROOT . '/.env') ? 'found' : 'not present (using defaults / real env vars)') . PHP_EOL;

    // Encoding canary: the rupee sign lives in config/app.php as a \u{20B9} escape, but an
    // older copy of that file (or one re-saved by an editor) may hold the literal glyph in a
    // non-UTF-8 encoding. That mangles into digits and shows up inside every price, so it is
    // checked here rather than discovered on the shop page.
    echo PHP_EOL . 'Currency encoding:' . PHP_EOL;
    $symbol = function_exists('currency_symbol') ? currency_symbol() : '';
    $configSource = @file_get_contents(APP_ROOT . '/config/app.php') ?: '';
    $configBytes = '';
    if (preg_match("/define\('CURRENCY_SYMBOL',\s*([^)]+)\)/", $configSource, $m)) {
        $configBytes = trim($m[1]);
    }
    $encodingOk = $symbol !== '' && preg_match('//u', $symbol) === 1 && !preg_match('/[0-9\x{FFFD}]/u', $symbol);
    $line($encodingOk, 'Currency symbol', 'renders as "' . $symbol . '"' . ($encodingOk ? '' : ' — CORRUPT, every price will be wrong. Replace config/app.php with the current copy from the repository.'));
    $configFileUtf8 = $configSource === '' || mb_check_encoding($configSource, 'UTF-8');
    $line($configFileUtf8, 'config/app.php', $configFileUtf8 ? 'valid UTF-8' : 'NOT valid UTF-8 — re-upload the file (FTP/editor saved it in the wrong encoding)');
    if (!$configFileUtf8) {
        $ok = false;
    }

    try {
        $tableCount = (int) Database::instance()->value("SELECT COUNT(*) FROM settings");
        echo 'Tables installed: ' . ($tableCount > 0 ? 'yes (settings seeded)' : 'schema present but empty — run: php tools/console.php install') . PHP_EOL;
    } catch (Throwable) {
        echo 'Tables installed: no — run: php tools/console.php install' . PHP_EOL;
    }

    // Sessions: a host whose session directory is unwritable, or whose GC lifetime is shorter
    // than the app's idle window, silently loses logins and CSRF tokens between requests.
    echo PHP_EOL . 'Sessions:' . PHP_EOL;
    $savePath = (string) ini_get('session.save_path');
    $gcLife = (int) ini_get('session.gc_maxlifetime');
    $writable = $savePath !== '' && is_dir($savePath) && is_writable($savePath);
    echo '  [ -- ]   save_path: ' . ($savePath !== '' ? $savePath : '(system default)') . PHP_EOL;
    $line($writable || $savePath === '', 'Session directory writable', $writable ? 'yes' : 'no — logins and form tokens will not persist; set session.save_path in php.ini or make storage/sessions writable');
    $line($gcLife >= SESSION_LIFETIME, 'GC lifetime vs idle timeout', 'gc_maxlifetime ' . $gcLife . 's vs app ' . SESSION_LIFETIME . 's'
        . ($gcLife >= SESSION_LIFETIME ? '' : ' — sessions are collected while still considered valid; the app raises gc_maxlifetime at runtime, but the host may override it'));
    if (!$writable && $savePath !== '') {
        $ok = false;
    }

    // Collation mix: MySQL will not compare strings whose collations differ, so a database
    // where some tables are utf8mb4_unicode_ci and others inherited the server default
    // (usually utf8mb4_general_ci) throws \"1267 Illegal mix of collations\" on ordinary
    // queries. That is a hosting artefact, not a code bug, so it is reported here.
    echo PHP_EOL . 'Collations:' . PHP_EOL;
    try {
        $probeDb = Database::instance();
        if ($probeDb->driver() !== 'mysql') {
            echo '  [ -- ]   Collation mix — not applicable on SQLite' . PHP_EOL;
        } else {
            $tableCollations = $probeDb->select(
                "SELECT TABLE_NAME AS t, TABLE_COLLATION AS c FROM information_schema.TABLES
                  WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' ORDER BY TABLE_NAME"
            );
            $serverDefault = (string) ($probeDb->value('SELECT @@collation_server') ?: '?');
            $distinct = [];
            foreach ($tableCollations as $row) {
                $distinct[(string) $row['c']][] = (string) $row['t'];
            }
            $mixed = count($distinct) > 1;
            echo '  [ -- ]   Server default collation: ' . $serverDefault . PHP_EOL;
            echo '  [ -- ]   Database default collation: ' . (string) ($probeDb->value('SELECT @@collation_database') ?: '?') . PHP_EOL;
            foreach ($distinct as $collation => $tables) {
                echo '  [ -- ]     ' . $collation . ': ' . count($tables) . ' table(s)'
                    . ($mixed ? ' — e.g. ' . implode(', ', array_slice($tables, 0, 6)) . (count($tables) > 6 ? ', …' : '') : '')
                    . PHP_EOL;
            }
            $connectionCollation = (string) ($probeDb->value('SELECT @@collation_connection') ?: '?');
            echo '  [ -- ]   Connection collation: ' . $connectionCollation . PHP_EOL;
            $line(!$mixed, 'Table collations consistent', $mixed
                ? 'MIXED — comparisons can fail with SQLSTATE 1267. Run: php tools/console.php collation:repair'
                : 'all tables use ' . (array_key_first($distinct) ?? 'n/a'));
            if ($mixed) {
                $ok = false;
            }
            // The 1253 check: every collation the app can emit must belong to the connection's
            // charset. A mismatch here is what produced
            // "COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'".
            $connectionCharset = (string) ($probeDb->value('SELECT @@character_set_connection') ?: '');
            $connectionCollationCharset = strstr($connectionCollation, '_', true) ?: $connectionCollation;
            $charsetOk = $connectionCharset === '' || $connectionCollationCharset === $connectionCharset;
            // A connection in a different charset than the tables cannot use their collation at
            // all — no COLLATE clause can bridge two character sets (error 1253).
            $tableCharsets = array_values(array_unique(array_map(
                static fn (string $c): string => strstr($c, '_', true) ?: $c,
                array_keys($distinct)
            )));
            $charsetMatchesTables = $connectionCharset === ''
                || $tableCharsets === []
                || in_array($connectionCharset, $tableCharsets, true);
            $line($charsetMatchesTables, 'Connection charset matches tables', $charsetMatchesTables
                ? $connectionCharset . ' (tables: ' . implode('/', $tableCharsets) . ')'
                : 'connection is ' . $connectionCharset . ' but tables are ' . implode('/', $tableCharsets)
                  . ' — run: php tools/console.php collation:heal');
            if (!$charsetMatchesTables) {
                $ok = false;
            }
            $line($charsetOk, 'Connection charset and collation agree', $charsetOk
                ? $connectionCharset . ' / ' . $connectionCollation
                : 'charset is ' . $connectionCharset . ' but collation is ' . $connectionCollation
                  . ' (error 1253) — run: php tools/console.php collation:heal');
            if (!$charsetOk) {
                $ok = false;
            }
            $expression = $probeDb->monthExpression('created_at');
            $expressionCharset = strstr((string) preg_replace('/^.*COLLATE\s+/', '', $expression), '_', true);
            $expressionOk = $expressionCharset === false || $expressionCharset === ''
                || $connectionCharset === '' || $expressionCharset === $connectionCharset;
            $line($expressionOk, 'Derived expressions use the connection charset', $expressionOk
                ? $expression
                : $expression . ' — belongs to ' . $expressionCharset . ' but connection is ' . $connectionCharset);
            $line(str_contains($expression, 'COLLATE'), 'Derived expressions pinned', $expression);
            if (!str_contains($expression, 'COLLATE')) {
                $ok = false;
            }
            // The connection collation is what bound parameters are compared with, so it has to
            // agree with the tables; otherwise SQLSTATE 1267 comes back on ordinary filters.
            $distinctCollations = array_keys($distinct);
            $connectionOk = count($distinctCollations) !== 1 || $connectionCollation === $distinctCollations[0];
            $line($connectionOk, 'Connection collation matches tables', $connectionOk
                ? $connectionCollation
                : 'connection is ' . $connectionCollation . ' but tables are ' . implode('/', $distinctCollations)
                  . ' — run: php tools/console.php collation:repair');
            if (!$connectionOk) {
                $ok = false;
            }
        }
    } catch (Throwable $e) {
        $line(false, 'Collations', 'could not inspect: ' . $e->getMessage());
        $ok = false;
    }

    // Schema drift: a live database can be missing tables or columns that the current code
    // reads. Drift is what produces "Unknown column" errors that never show up on a fresh
    // install, so it is worth reporting before a page does.
    echo PHP_EOL . 'Schema drift:' . PHP_EOL;
    try {
        $probe = Database::instance();
        $missingTables = [];
        $missingColumns = [];
        foreach (\Saee\Lib\Schema::tables() as $table => $definition) {
            $cols = [];
            try {
                if ($probe->driver() === 'mysql') {
                    foreach ($probe->select('SHOW COLUMNS FROM `' . $table . '`') as $row) {
                        $cols[] = (string) $row['Field'];
                    }
                } else {
                    foreach ($probe->select('PRAGMA table_info("' . $table . '")') as $row) {
                        $cols[] = (string) $row['name'];
                    }
                }
            } catch (Throwable) {
                $missingTables[] = $table;
                continue;
            }
            foreach (array_keys($definition['columns']) as $column) {
                if (!in_array($column, $cols, true)) {
                    $missingColumns[] = $table . '.' . $column;
                }
            }
        }
        $line($missingTables === [], 'Tables', $missingTables === [] ? 'all present' : 'missing: ' . implode(', ', $missingTables));
        $line($missingColumns === [], 'Columns', $missingColumns === [] ? 'all present' : count($missingColumns) . ' missing: ' . implode(', ', array_slice($missingColumns, 0, 8)) . (count($missingColumns) > 8 ? ' …' : ''));
        if ($missingTables !== [] || $missingColumns !== []) {
            echo '  Fix: php tools/console.php upgrade' . PHP_EOL;
            $ok = false;
        }
    } catch (Throwable $e) {
        $line(false, 'Schema drift check', $e->getMessage());
    }

    echo PHP_EOL . ($ok ? 'Everything looks good.' : 'Fix the [FAIL] lines above, then run install again.') . PHP_EOL;
    exit($ok ? 0 : 1);
}

// Development aid: make bind-parameter mismatches fatal so tests catch them.
if (getenv('SAEE_STRICT_BIND') === '1') {
    echo 'Strict SQL binding enabled: mismatched placeholders will fail loudly.' . PHP_EOL;
}

if ($driver === 'mysql' && !in_array('mysql', \PDO::getAvailableDrivers(), true)) {
    fwrite(STDERR, "PDO MySQL driver (pdo_mysql) is not enabled in this PHP build." . PHP_EOL);
    fwrite(STDERR, "Enable it in php.ini: remove the ';' in front of extension=pdo_mysql, then restart." . PHP_EOL);
    fwrite(STDERR, "Or set DB_DRIVER=sqlite in .env to install on SQLite for now." . PHP_EOL);
    exit(1);
}

try {
    $db = Database::instance();
} catch (Throwable $e) {
    fwrite(STDERR, "Cannot connect to the database (" . $driver . ")." . PHP_EOL);
    fwrite(STDERR, "Reason: " . $e->getMessage() . PHP_EOL . PHP_EOL);
    if ($driver === 'mysql') {
        fwrite(STDERR, "Checklist for XAMPP/MySQL:" . PHP_EOL);
        fwrite(STDERR, "  1. Start MySQL in the XAMPP control panel." . PHP_EOL);
        fwrite(STDERR, "  2. Create the database (phpMyAdmin -> New, e.g. saee_life_herbal, utf8mb4)." . PHP_EOL);
        fwrite(STDERR, "  3. Put the details in .env:  DB_DRIVER=mysql, DB_DATABASE=..., DB_USERNAME=root, DB_PASSWORD= (blank on XAMPP by default)." . PHP_EOL);
        fwrite(STDERR, "  4. Make sure extension=pdo_mysql is enabled in php.ini." . PHP_EOL);
    } else {
        fwrite(STDERR, "Checklist for SQLite:" . PHP_EOL);
        fwrite(STDERR, "  1. extension=pdo_sqlite must be enabled in php.ini." . PHP_EOL);
        fwrite(STDERR, "  2. The storage/db folder must be writable by PHP." . PHP_EOL);
    }
    fwrite(STDERR, PHP_EOL . "Run 'php tools/console.php' with no arguments to see all commands and the active driver." . PHP_EOL);
    exit(1);
}

switch ($command) {
    case 'selftest':
        // Exercises the query layer against the configured database: repeated named
        // placeholders, positional params, literals containing colons, and error paths.
        $checks = [];
        $run = static function (string $label, callable $fn) use (&$checks): void {
            try {
                $fn();
                $checks[] = [true, $label, ''];
            } catch (Throwable $e) {
                $checks[] = [false, $label, $e->getMessage()];
            }
        };
        $run('repeated named placeholder', static fn () => $db->value('SELECT :q AS a', ['q' => 'same-value']) === 'same-value' ?: throw new RuntimeException('value mismatch'));
        $run('placeholder repeated twice binds one value', static function () use ($db) {
            $v = $db->value("SELECT (:q || '|' || :q) AS v", ['q' => 'ab']);
            if ($v !== 'ab|ab') { throw new RuntimeException('expected ab|ab, got ' . var_export($v, true)); }
        });
        $run('positional parameter', static fn () => $db->value('SELECT ? AS v', ['pos']) === 'pos' ?: throw new RuntimeException('value mismatch'));
        $run('colon inside a string literal is not a parameter', static fn () => $db->value("SELECT 'x:y' AS v", []) === 'x:y' ?: throw new RuntimeException('value mismatch'));
        $run('missing parameter is reported clearly', static function () use ($db) {
            try {
                $db->value('SELECT :missing AS v', []);
            } catch (RuntimeException $e) {
                if (str_contains($e->getMessage(), ':missing')) { return; }
                throw $e;
            }
            throw new RuntimeException('missing parameter was not reported');
        });
        $run('schema reachable', static fn () => $db->value('SELECT COUNT(*) FROM settings') !== null ?: throw new RuntimeException('no settings table'));
        foreach ($checks as [$pass, $label, $detail]) {
            echo ($pass ? '  [ OK ]   ' : '  [FAIL]  ') . $label . ($detail !== '' ? ' — ' . $detail : '') . PHP_EOL;
        }
        $failed = count(array_filter($checks, static fn ($c) => !$c[0]));
        echo PHP_EOL . ($failed === 0 ? 'Query layer OK on driver "' . $db->driver() . '".' : $failed . ' check(s) failed.') . PHP_EOL;
        exit($failed === 0 ? 0 : 1);

    case 'install':
        $installer = new Installer($db);
        $installer->install([
            'name' => $opts['admin-name'] ?? 'Super Administrator',
            'email' => $opts['admin-email'] ?? 'admin@saeelifeherbal.com',
            'username' => $opts['admin-user'] ?? 'admin',
            'password' => $opts['admin-pass'] ?? 'Admin@12345',
        ], !isset($opts['no-fresh']));
        echo "Installed schema on driver: " . $db->driver() . PHP_EOL;
        echo "Admin login: " . ($opts['admin-user'] ?? 'admin') . " / " . ($opts['admin-pass'] ?? 'Admin@12345') . PHP_EOL;
        break;

    case 'upgrade':
        // Columns first, then tables: ensureTables() internally also runs
        // ensureColumns() for existing tables, so running it first would add the
        // columns silently and the report below would wrongly print
        // "No missing columns" even though columns were just added. Running the
        // column pass first makes the report truthful.
        $addedColumns = [];
        foreach (\Saee\Lib\Schema::tables() as $table => $definition) {
            foreach (\Saee\Lib\Schema::ensureColumns($db, $table, $definition) as $entry) {
                $addedColumns[] = $entry;
            }
        }
        $created = \Saee\Lib\Schema::ensureTables($db);
        if ($created === []) {
            echo "No missing tables." . PHP_EOL;
        } else {
            echo "Created " . count($created) . " table(s): " . implode(', ', $created) . PHP_EOL;
        }
        if ($addedColumns === []) {
            echo "No missing columns." . PHP_EOL;
        } else {
            echo "Added " . count($addedColumns) . " missing column(s): " . implode(', ', $addedColumns) . PHP_EOL;
        }
        if ($created === [] && $addedColumns === []) {
            echo "Schema already up to date." . PHP_EOL;
        }
        // Order lifecycle: the eight-value status list was shortened to
        // new -> processing -> shipped -> delivered (+ cancelled). Existing rows still carry the
        // old words, and ensureColumns() never rewrites values or narrows a column, so this runs
        // its own migration.
        foreach (\Saee\Lib\Schema::migrateOrderStatuses($db) as $line) {
            echo $line . PHP_EOL;
        }
        // The MLM settings form wrote to the wrong store, so the commission engine never saw the
        // plan switch. Reconcile it here or an upgraded install keeps calculating nothing.
        $mlmLines = \Saee\Lib\Schema::migrateMlmSettings($db);
        if ($mlmLines === []) {
            echo "MLM settings already consistent." . PHP_EOL;
        }
        foreach ($mlmLines as $line) {
            echo $line . PHP_EOL;
        }
        // Auto-payout enum: ensureColumns() only adds columns, it never widens an ENUM.
        // Existing MySQL installs need the 'processing' value added explicitly, otherwise
        // moving a payout to "in progress" fails with a truncated-value error.
        if ($db->driver() === 'mysql') {
            try {
                $db->statement("ALTER TABLE payout_requests MODIFY status ENUM('pending','approved','processing','rejected','paid','cancelled') NOT NULL DEFAULT 'pending'");
                echo "Payout status enum widened (processing)." . PHP_EOL;
            } catch (\Throwable $e) {
                echo "Payout enum widen skipped: " . $e->getMessage() . PHP_EOL;
            }
        }
        // 2026-11 menu simplification: the public header is now a flat list with no submenus,
        // Products opens the full catalogue, Solutions opens agriculture solutions and Gallery
        // mixes photos + videos. Upgrades rewrite the header rows to match; anything the admin
        // added by hand as a top-level item is preserved.
        $now = date('Y-m-d H:i:s');
        $db->execute('DELETE FROM navigation_menus WHERE position = :p AND parent_id IS NOT NULL', ['p' => 'header']);
        $flat = [
            ['Home', 'home'], ['About Us', 'about-us'], ['Products', 'our-products'],
            ['Solutions', 'agriculture-solutions'], ['Gallery', 'gallery'],
            ['Business Opportunity', 'mlm-business-opportunity'], ['Contact', 'contact-us'], ['B2B', 'b2b'],
        ];
        $touched = 0;
        foreach ($flat as $i => [$label, $url]) {
            $existing = $db->first(
                'SELECT id FROM navigation_menus WHERE position = :p AND (url = :u OR label = :l) ORDER BY id LIMIT 1',
                ['p' => 'header', 'u' => $url, 'l' => $label]
            );
            if ($existing) {
                $db->update('navigation_menus', ['label' => $label, 'url' => $url, 'parent_id' => null, 'sort_order' => $i + 1, 'status' => 1, 'updated_at' => $now], 'id = :id', ['id' => $existing['id']]);
            } else {
                $db->insert('navigation_menus', ['parent_id' => null, 'label' => $label, 'url' => $url, 'icon' => null, 'target' => '_self', 'position' => 'header', 'sort_order' => $i + 1, 'status' => 1, 'created_at' => $now, 'updated_at' => $now]);
            }
            $touched++;
        }
        echo "Header menu normalised (" . $touched . " items, no submenus)." . PHP_EOL;
        // Auto-payout notification template for existing installs (insertIgnore keeps
        // a customised template untouched).
        $db->insertIgnore('notification_templates', [
            'code' => 'payout_in_progress',
            'name' => 'Payout In Progress',
            'channel' => 'in_system',
            'subject' => 'Payout In Progress',
            'body' => 'Your payout {{request_code}} of {{amount}} is now in progress. The accounts team has downloaded the payout report and payment is being processed to your bank account.',
            'variables' => 'request_code,amount',
            'status' => 1,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        echo "Payout in-progress template ensured." . PHP_EOL;
        break;

    case 'production:reset':
        // Destructive by design: this rebuilds the database from nothing. Guard it behind an
        // explicit flag so it can never run by accident from a shell history mistake.
        if (empty($opts['yes'])) {
            fwrite(STDERR, "This DELETES every member, order, payout, commission and wallet in the database." . PHP_EOL);
            fwrite(STDERR, "Re-run with --yes to confirm:  php tools/console.php production:reset --yes" . PHP_EOL);
            exit(1);
        }

        echo "Rebuilding the database from scratch..." . PHP_EOL;
        $installer = new Installer($db);
        // fresh = true drops every table first, so nothing from the previous installation
        // survives — not even rows in tables this script does not know about.
        $installer->install([
            'name' => $opts['admin-name'] ?? 'Super Administrator',
            'email' => $opts['admin-email'] ?? 'admin@saeelifeherbal.com',
            'username' => $opts['admin-user'] ?? 'admin',
            'password' => $opts['admin-pass'] ?? 'Admin@12345',
        ], true);

        // Catalogue and website content only. No demo partners, no demo orders.
        (new \Saee\Lib\DemoSeeder($db))->seedContent();

        $chain = (new \Saee\Lib\CompanyStructure($db))->seed();

        echo PHP_EOL . "Production database ready." . PHP_EOL;
        echo "  Admin login    : " . ($opts['admin-user'] ?? 'admin') . " / " . ($opts['admin-pass'] ?? 'Admin@12345') . PHP_EOL;
        echo "  Company chain  : " . implode('  ->  ', array_keys($chain)) . PHP_EOL;
        echo "  Sponsor fallback: " . \Saee\Lib\MlmSettings::companySponsorCode() . PHP_EOL;
        echo "  Next member ID : SLH" . str_pad('1', (int) \Saee\Lib\MlmSettings::get('member_id_padding', 6), '0', STR_PAD_LEFT)
            . "  (password = the member ID)" . PHP_EOL;
        break;

    case 'demo':
        (new \Saee\Lib\DemoSeeder($db))->run();
        echo "Demo data seeded." . PHP_EOL;
        break;

    case 'schema:mysql':
        file_put_contents(APP_ROOT . '/database/schema.sql', \Saee\Lib\Schema::mysqlScript());
        echo "Wrote database/schema.sql" . PHP_EOL;
        break;

    case 'schema:sqlite':
        file_put_contents(APP_ROOT . '/database/schema.sqlite.sql', \Saee\Lib\Schema::sqliteScript());
        echo "Wrote database/schema.sqlite.sql" . PHP_EOL;
        break;

    case 'logs':
        // The production error page shows a reference like ERR-8F3A21C4. This is where it
        // leads: a readable view of storage/logs so an operator never has to ask the host for
        // an error log path.
        $dir = LOG_PATH;
        $files = glob($dir . '/*.log') ?: [];
        if ($files === []) {
            echo "No log files in " . $dir . PHP_EOL;
            break;
        }
        if (!empty($opts['clear'])) {
            foreach ($files as $file) {
                @file_put_contents($file, '');
            }
            echo "Cleared " . count($files) . " log file(s)." . PHP_EOL;
            break;
        }
        $tail = max(1, (int) ($opts['tail'] ?? 40));
        $grep = (string) ($opts['grep'] ?? '');
        foreach ($files as $file) {
            $name = basename($file);
            if ($grep !== '') {
                $lines = [];
                foreach (file($file, FILE_IGNORE_NEW_LINES) ?: [] as $line) {
                    if (stripos($line, $grep) !== false) {
                        $lines[] = $line;
                    }
                }
                if ($lines === []) {
                    continue;
                }
                $lines = array_slice($lines, -$tail);
                echo PHP_EOL . '== ' . $name . ' — ' . count($lines) . ' line(s) matching "' . $grep . '" ==' . PHP_EOL;
            } else {
                $all = file($file, FILE_IGNORE_NEW_LINES) ?: [];
                $lines = array_slice($all, -$tail);
                echo PHP_EOL . '== ' . $name . ' (last ' . count($lines) . ' of ' . count($all) . ') ==' . PHP_EOL;
            }
            foreach ($lines as $line) {
                echo '  ' . $line . PHP_EOL;
            }
        }
        echo PHP_EOL . 'Tip: the reference on the error page (ERR-XXXXXXXX) is on the matching line, '
            . 'e.g. php tools/console.php logs --grep=ERR-8F3A21C4' . PHP_EOL;
        break;

    case 'collation:repair':
        if ($db->driver() !== 'mysql') {
            echo "Not applicable: driver is \"" . $db->driver() . "\". Collations only affect MySQL/MariaDB." . PHP_EOL;
            break;
        }
        // The target must be a collation the live connection can actually use: converting to a
        // collation outside the connection's charset is what produces
        // 1253 COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET 'latin1'.
        $target = (string) ($opts['collation'] ?? '') ?: (string) ($db->expressionCollation() ?: '');
        if ($target === '') {
            $target = (string) ($db->value('SELECT @@collation_connection') ?: '');
        }
        if ($target === '') {
            echo 'Could not determine a collation to use. Pass one explicitly: '
                . 'php tools/console.php collation:repair --collation=utf8mb4_unicode_ci' . PHP_EOL;
            break;
        }
        $connectionCharset = (string) ($db->value('SELECT @@character_set_connection') ?: '');
        $targetCharset = strstr($target, '_', true) ?: $target;
        if ($connectionCharset !== '' && $targetCharset !== $connectionCharset) {
            echo 'Refusing to convert: the connection charset is ' . $connectionCharset
                . ' but ' . $target . ' belongs to ' . $targetCharset . '.' . PHP_EOL
                . 'That combination is rejected by MySQL with error 1253.' . PHP_EOL
                . 'Pass a collation of the connection charset, e.g. --collation='
                . $connectionCharset . '_' . ($connectionCharset === 'utf8mb4' ? 'unicode_ci' : 'general_ci') . PHP_EOL;
            break;
        }
        echo "Converting every table to $target ..." . PHP_EOL;
        $repaired = $db->repairCollations($target);
        if ($repaired === []) {
            echo "Nothing to do: every table already uses $target." . PHP_EOL;
        } else {
            echo "Converted " . count($repaired) . " table(s): " . implode(', ', $repaired) . PHP_EOL;
        }
        break;

    case 'warn:scan':
        // Static pass for the bug class behind the payout-report warning wall: a template reading
        // $row['key'] directly is fine only while the query keeps returning that exact key. Report
        // the unguarded reads so a schema change surfaces here instead of on a user's screen.
        $targets = $opts['path'] ?? 'admin';
        $dir = APP_ROOT . DIRECTORY_SEPARATOR . $targets;
        if (!is_dir($dir)) {
            echo 'No such directory: ' . $targets . PHP_EOL;
            break;
        }
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS));
        $total = 0;
        $flagged = 0;
        foreach ($files as $file) {
            if (!$file->isFile() || $file->getExtension() !== 'php') {
                continue;
            }
            $total++;
            $lines = file($file->getPathname()) ?: [];
            $hits = [];
            foreach ($lines as $n => $line) {
                // $row['x'], $r['x'] etc. with no ?? and no array_key_exists/isset guard on the line.
                if (preg_match_all('/\$(?:row|r|item|record)\s*\[\s*\'([a-z0-9_]+)\'\s*\]/i', $line, $m)) {
                    if (str_contains($line, '??') || stripos($line, 'isset(') !== false || stripos($line, 'array_key_exists') !== false) {
                        continue;
                    }
                    $hits[] = ($n + 1) . ': ' . trim($line);
                }
            }
            if ($hits !== []) {
                $flagged++;
                echo PHP_EOL . str_replace(APP_ROOT . DIRECTORY_SEPARATOR, '', $file->getPathname()) . PHP_EOL;
                foreach (array_slice($hits, 0, 12) as $h) {
                    echo '  ' . $h . PHP_EOL;
                }
                if (count($hits) > 12) {
                    echo '  ... ' . (count($hits) - 12) . ' more' . PHP_EOL;
                }
            }
        }
        echo PHP_EOL . $total . ' file(s) scanned, ' . $flagged . ' with unguarded array reads.' . PHP_EOL;
        echo 'These are correct only while the query returns those exact keys; add ?? when a query changes.' . PHP_EOL;
        break;

    case 'seed-payouts':
        // Development helper: the demo seed creates payouts only if a member has a bank
        // account, so report pages can render with zero rows and hide their own bugs.
        if ($db->driver() !== 'sqlite') {
            echo 'Refusing: this seeds test rows, so it only runs on the SQLite development database.' . PHP_EOL;
            break;
        }
        $member = $db->first('SELECT id, member_code FROM members ORDER BY id LIMIT 1');
        if (!$member) {
            echo 'No members: run php tools/console.php demo first.' . PHP_EOL;
            break;
        }
        $made = 0;
        foreach ([['pending', null], ['approved', null], ['paid', 'UTR1234567890'], ['rejected', null]] as $i => [$status, $utr]) {
            $code = 'PR' . date('ymd') . strtoupper(bin2hex(random_bytes(2)));
            $net = 1000 + $i * 250;
            $db->insert('payout_requests', [
                'request_code' => $code,
                'member_id' => (int) $member['id'],
                'amount' => $net + 50,
                'deduction_amount' => 50,
                'net_amount' => $net,
                'status' => $status,
                'bank_snapshot' => json_encode(['bank_name' => 'Test Bank', 'account_holder_name' => 'Test Holder', 'account_number' => '123456789012', 'ifsc_code' => 'TEST0000123']),
                'requested_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 3) . ' days')),
                'paid_at' => $status === 'paid' ? date('Y-m-d H:i:s', strtotime('-1 day')) : null,
                'utr_number' => $utr,
                'created_at' => date('Y-m-d H:i:s', strtotime('-' . ($i * 3) . ' days')),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            $made++;
        }
        echo "Seeded $made payout request(s) for " . $member['member_code'] . '.' . PHP_EOL;
        break;

    case 'collation:heal':
        // One-shot repair for a database whose connection charset and tables disagree. This is
        // the state behind "COLLATION 'utf8mb4_unicode_ci' is not valid for CHARACTER SET
        // 'latin1'" on installs that were imported with a different server default.
        if ($db->driver() !== 'mysql') {
            echo 'Not applicable: driver is "' . $db->driver() . '".' . PHP_EOL;
            break;
        }
        echo 'Connection charset: ' . (string) ($db->value('SELECT @@character_set_connection') ?: '?') . PHP_EOL;
        echo 'Connection collation: ' . (string) ($db->value('SELECT @@collation_connection') ?: '?') . PHP_EOL;
        $tables = $db->column(
            "SELECT DISTINCT TABLE_COLLATION FROM information_schema.TABLES
              WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_COLLATION IS NOT NULL"
        );
        echo 'Table collations: ' . (implode(', ', $tables) ?: 'none') . PHP_EOL;
        if (!$db->hasCharsetMismatch()) {
            echo 'Nothing to do: connection and tables already agree.' . PHP_EOL;
            break;
        }
        echo 'Mismatch detected — converting tables to the application charset ...' . PHP_EOL;
        $changed = $db->healCharsetMismatch();
        if ($changed === []) {
            echo 'No table was converted. The database account probably lacks ALTER permission.' . PHP_EOL
                . 'Fix it manually in phpMyAdmin: Operations → Collation → utf8mb4_general_ci, or ask your host.' . PHP_EOL;
            exit(1);
        }
        echo 'Converted ' . count($changed) . ' table(s): ' . implode(', ', $changed) . PHP_EOL;
        echo 'Connection now: ' . (string) ($db->value('SELECT @@character_set_connection') ?: '?')
            . ' / ' . (string) ($db->value('SELECT @@collation_connection') ?: '?') . PHP_EOL;
        echo 'Done. Reload the page.' . PHP_EOL;
        break;

    case 'mlm:drop-commission-rules':
        // Commission moved onto the product row itself, so this table is dead weight on an
        // upgraded install. Dropping it destroys the old rule history, which is why the console
        // asks rather than doing it on boot. Commission already credited lives in
        // commission_transactions and is untouched.
        if (!$db->statement("SELECT name FROM sqlite_master WHERE type='table' AND name='mlm_commission_rules'")->fetch()
            && $db->driver() === 'sqlite') {
            echo "mlm_commission_rules does not exist - nothing to do." . PHP_EOL;
            break;
        }
        if ($db->driver() !== 'sqlite') {
            $exists = (bool) $db->value(
                'SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = ?',
                ['mlm_commission_rules']
            );
            if (!$exists) {
                echo "mlm_commission_rules does not exist - nothing to do." . PHP_EOL;
                break;
            }
        }
        $rows = (int) $db->value('SELECT COUNT(*) FROM mlm_commission_rules');
        if (!in_array('--force', $argv, true)) {
            echo "mlm_commission_rules holds $rows row(s) of retired rule history." . PHP_EOL;
            echo "Re-run with --force to drop the table. Commission already credited is unaffected." . PHP_EOL;
            break;
        }
        if ($db->driver() === 'sqlite') {
            $db->statement('PRAGMA foreign_keys = OFF');
        } else {
            $db->statement('SET FOREIGN_KEY_CHECKS = 0');
        }
        $db->statement('DROP TABLE IF EXISTS mlm_commission_rules');
        if ($db->driver() === 'sqlite') {
            $db->statement('PRAGMA foreign_keys = ON');
        } else {
            $db->statement('SET FOREIGN_KEY_CHECKS = 1');
        }
        echo "Dropped mlm_commission_rules ($rows row(s) of retired history)." . PHP_EOL;
        break;

    case 'commissions:process':
        $n = (new \Saee\Lib\MlmCommissionEngine($db))->processEligibleOrders();
        echo "Processed $n order(s)." . PHP_EOL;
        break;

    case 'payouts:auto':
        // Auto payouts also sync on report/member page loads; this cron entry keeps
        // the register current even when nobody opens those pages.
        $n = (new \Saee\Lib\PayoutService($db))->syncAutoPayouts();
        echo "Created $n auto payout(s)." . PHP_EOL;
        break;

    case 'royalty:run':
        $n = (new \Saee\Lib\RoyaltyEngine($db))->processAll();
        echo "Evaluated royalty for $n member(s)." . PHP_EOL;
        break;

    case 'backups:run':
        $file = (new \Saee\Lib\BackupService($db))->create('cli');
        echo "Backup written: $file" . PHP_EOL;
        break;

    case 'sitemap':
        file_put_contents(APP_ROOT . '/sitemap.xml', (new \Saee\Lib\SeoService($db))->sitemapXml());
        echo "Sitemap generated." . PHP_EOL;
        break;

}
