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

$db = Database::instance();

switch ($command) {
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

    default:
        echo "Saee Life Herbal console\n\nCommands:\n  install, demo, schema:mysql, schema:sqlite, commissions:process, royalty:run, backups:run, sitemap\n";
}
