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
$commands = ['install', 'upgrade', 'demo', 'doctor', 'selftest', 'schema:mysql', 'schema:sqlite', 'commissions:process', 'royalty:run', 'backups:run', 'sitemap'];

if (!in_array($command, $commands, true)) {
    echo "Saee Life Herbal console\n\n";
    echo "Usage: php tools/console.php <command> [options]\n\n";
    echo "  install              create all tables, settings, roles and the first admin\n";
    echo "      --admin-user=admin --admin-pass='Admin@12345'\n";
    echo "      --admin-email=a@b.com --admin-name='Name' --no-fresh\n";
    echo "  upgrade              create tables added by newer versions (safe to re-run)\n";
    echo "  demo                 seed demo catalogue, members, orders and commissions\n";
    echo "  schema:mysql         write database/schema.sql from the schema definition\n";
    echo "  schema:sqlite        write database/schema.sqlite.sql\n";
    echo "  commissions:process  release matured commission holds (cron)\n";
    echo "  royalty:run          evaluate royalty achievements (cron)\n";
    echo "  backups:run          write a database backup into storage/backups (cron)\n";
    echo "  sitemap              regenerate sitemap.xml (cron)\n";
    echo "  doctor               check PHP version, extensions, folders and database connectivity\n";
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
        $created = \Saee\Lib\Schema::ensureTables($db);
        if ($created === []) {
            echo "No missing tables." . PHP_EOL;
        } else {
            echo "Created " . count($created) . " table(s): " . implode(', ', $created) . PHP_EOL;
        }
        // Report (and repair) column drift too: ensureTables() already ran ensureColumns()
        // for every existing table, so re-run it here purely to print what was added.
        $addedColumns = [];
        foreach (\Saee\Lib\Schema::tables() as $table => $definition) {
            foreach (\Saee\Lib\Schema::ensureColumns($db, $table, $definition) as $entry) {
                $addedColumns[] = $entry;
            }
        }
        if ($addedColumns === []) {
            echo "No missing columns." . PHP_EOL;
        } else {
            echo "Added " . count($addedColumns) . " missing column(s): " . implode(', ', $addedColumns) . PHP_EOL;
        }
        if ($created === [] && $addedColumns === []) {
            echo "Schema already up to date." . PHP_EOL;
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

    case 'commissions:process':
        $n = (new \Saee\Lib\MlmCommissionEngine($db))->processEligibleOrders();
        echo "Processed $n order(s)." . PHP_EOL;
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
