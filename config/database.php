<?php
/**
 * Database configuration loader.
 * Environment specific values are read from config/config.php (created by the installer).
 * When that file is absent the application falls back to a local SQLite database so the
 * platform can be previewed without a MySQL server. Production installs must use MySQL.
 */
declare(strict_types=1);

$configFile = CONFIG_PATH . DS . 'config.php';
$config = [];
if (is_file($configFile)) {
    /** @var array $config */
    $config = require $configFile;
}

// Environment variables (.env) override the defaults; config/config.php overrides both.
$env = static function (string $key, ?string $default = null): ?string {
    $value = getenv($key);
    return ($value === false || $value === '') ? $default : $value;
};

/**
 * Driver default: the bundled SQLite file keeps the site runnable without any setup,
 * but on a typical XAMPP/WAMP/LAMP box the developer typed DB details in .env and
 * expects MySQL. If DB_DRIVER is not set but MySQL credentials are, use MySQL.
 */
$defaultDriver = 'sqlite';
if ($env('DB_DRIVER') === null && ($env('DB_DATABASE') !== null || $env('DB_USERNAME') !== null)) {
    $defaultDriver = 'mysql';
}

$db = array_merge([
    'driver'   => $defaultDriver,
    'host'     => '127.0.0.1',
    'port'     => 3306,
    'database' => 'saee_life_herbal',
    'username' => 'root',
    'password' => '',
    'charset'  => 'utf8mb4',
    // Connection collation. The DSN charset alone leaves MySQL on utf8mb4_general_ci, which
    // does not match the utf8mb4_unicode_ci tables and raises SQLSTATE 1267 on comparisons.
    'collation' => 'utf8mb4_unicode_ci',
    'sqlite_path' => STORAGE_PATH . DS . 'db' . DS . 'app.sqlite',
], array_filter([
    'driver' => $env('DB_DRIVER'),
    'host' => $env('DB_HOST'),
    'port' => $env('DB_PORT'),
    'database' => $env('DB_DATABASE'),
    'username' => $env('DB_USERNAME'),
    'password' => $env('DB_PASSWORD', null),
    'collation' => $env('DB_COLLATION', null),
], static fn ($v) => $v !== null), $config['db'] ?? []);

if ($db['driver'] === 'sqlite' && !empty($db['sqlite_path']) && !str_starts_with($db['sqlite_path'], '/')) {
    $db['sqlite_path'] = STORAGE_PATH . DS . 'db' . DS . basename($db['sqlite_path']);
}
@mkdir(STORAGE_PATH . DS . 'db', 0775, true);

return [
    'db'   => $db,
    'app'  => array_merge([
        'name'        => APP_NAME,
        'url'         => (string) ($env('APP_URL') ?? ''),
        'environment' => APP_ENV,
        'debug'       => APP_DEBUG,
    ], $config['app'] ?? []),
    'security' => array_merge([
        'encryption_key' => (string) ($env('SAEE_ENCRYPTION_KEY') ?? ''),
        'force_https'   => filter_var($env('SESSION_SECURE', '0'), FILTER_VALIDATE_BOOLEAN),
        'trusted_proxies' => [],
    ], $config['security'] ?? []),
    'mail' => array_merge([
        'transport' => 'log',
        'from'      => 'no-reply@saeelifeherbal.com',
        'from_name' => 'Saee Life Herbal',
        'host' => '', 'port' => 587, 'username' => '', 'password' => '', 'encryption' => 'tls',
    ], $config['mail'] ?? []),
    'payment' => array_merge([
        'razorpay_key' => '', 'razorpay_secret' => '', 'razorpay_webhook_secret' => '',
    ], $config['payment'] ?? []),
];
