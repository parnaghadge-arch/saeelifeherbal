<?php
/**
 * Application bootstrap - shared by the website, admin panel, member portal and APIs.
 */
declare(strict_types=1);

/**
 * Hard requirement check, kept deliberately early and syntax-free so an unsupported PHP
 * version produces a readable message instead of a parse error or a blank "500".
 * XAMPP still ships PHP 7.x in some bundles; this app needs 8.1+.
 */
if (PHP_VERSION_ID < 80100) {
    $needed = '8.1 or newer';
    $have = PHP_VERSION;
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, 'Saee Life Herbal requires PHP ' . $needed . '; this CLI runs PHP ' . $have . '.' . PHP_EOL);
        fwrite(STDERR, 'Point the php binary at a newer version (XAMPP: C:\\xampp\\php\\php.exe must be 8.1+).' . PHP_EOL);
        exit(1);
    }
    http_response_code(500);
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Unsupported PHP version</title>'
        . '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f6f8f6;padding:48px 16px;color:#26332b}'
        . '.box{max-width:620px;margin:0 auto;background:#fff;border:1px solid #dfe8e1;border-radius:14px;padding:28px 32px}'
        . 'code{background:#f1f5f2;padding:2px 6px;border-radius:5px}</style></head><body><div class="box">'
        . '<h1 style="font-size:20px;margin-top:0">Unsupported PHP version</h1>'
        . '<p>Saee Life Herbal requires <strong>PHP ' . htmlspecialchars($needed) . '</strong>, '
        . 'but this server runs <strong>PHP ' . htmlspecialchars($have) . '</strong>.</p>'
        . '<p>Switch your server to PHP 8.1+ (XAMPP: Apache config &rarr; PHP 8.x, or download a newer XAMPP build), then reload.</p>'
        . '</div></body></html>';
    exit;
}

require_once dirname(__DIR__) . '/config/app.php';
require_once CONFIG_PATH . '/constants.php';
require_once LIB_PATH . '/Helpers.php';

use Saee\Lib\Config;
use Saee\Lib\Settings;

// Friendly error page for uncaught exceptions in production.
set_exception_handler(static function (Throwable $e): void {
    error_log('[uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());

    // On the command line always show the real error: a CLI user (installer, cron)
    // has no browser to look at, and a friendly message would hide the cause.
    if (PHP_SAPI === 'cli') {
        $label = $e instanceof \PDOException ? 'Database error' : 'Error';
        fwrite(STDERR, PHP_EOL . $label . ': ' . $e->getMessage() . PHP_EOL);
        fwrite(STDERR, 'File: ' . $e->getFile() . ':' . $e->getLine() . PHP_EOL);
        if (getenv('SAEE_TRACE') || APP_DEBUG) {
            fwrite(STDERR, $e->getTraceAsString() . PHP_EOL);
        }
        $prev = $e->getPrevious();
        while ($prev !== null) {
            fwrite(STDERR, 'Caused by: ' . $prev->getMessage() . PHP_EOL);
            $prev = $prev->getPrevious();
        }
        fwrite(STDERR, PHP_EOL . 'Need more detail? Re-run with SAEE_DEBUG=1 (or SAEE_TRACE=1 for a stack trace).' . PHP_EOL);
        exit(1);
    }

    if (APP_DEBUG) {
        http_response_code(500);
        echo '<pre style="padding:24px;background:#111;color:#eee;white-space:pre-wrap">';
        echo htmlspecialchars($e->getMessage() . "\n\n" . $e->getFile() . ':' . $e->getLine() . "\n\n" . $e->getTraceAsString());
        echo '</pre>';
        return;
    }
    http_response_code(500);
    $path = APP_ROOT . '/errors/500.php';
    if (is_file($path)) {
        include $path;
    } else {
        echo 'Something went wrong. Please try again later.';
    }
});

// Security headers for every response.
if (!headers_sent() && PHP_SAPI !== 'cli') {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('X-XSS-Protection: 0');
    header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
}

/**
 * Give the operator an actionable message when the database cannot be reached, instead of a
 * generic failure. Exporting SAEE_SKIP_DB_CHECK=1 bypasses this (used before install).
 */
if (PHP_SAPI !== 'cli' && getenv('SAEE_SKIP_DB_CHECK') !== '1') {
    try {
        \Saee\Lib\Database::instance();
    } catch (Throwable $e) {
        $dbConfig = (array) Config::get('database.db');
        $driver = (string) ($dbConfig['driver'] ?? 'sqlite');
        error_log('[db] ' . $e->getMessage());
        if (APP_DEBUG) {
            http_response_code(500);
            echo '<pre style="padding:24px;background:#111;color:#eee;white-space:pre-wrap">';
            echo htmlspecialchars('Database connection failed (' . $driver . '): ' . $e->getMessage());
            echo '</pre>';
            return;
        }
        http_response_code(503);
        $hint = $driver === 'mysql'
            ? 'Check DB_HOST, DB_PORT, DB_DATABASE, DB_USERNAME and DB_PASSWORD in your .env file, and make sure the MySQL service is running in your hosting control panel (XAMPP).'
            : 'Check that the storage/db folder is writable so the SQLite file can be created.';
        echo '<!doctype html><html lang="en"><head><meta charset="utf-8"><title>Database unavailable</title>'
            . '<style>body{font-family:system-ui,Segoe UI,Arial,sans-serif;background:#f6f8f6;color:#26332b;margin:0;padding:48px 16px}'
            . '.box{max-width:640px;margin:0 auto;background:#fff;border:1px solid #dfe8e1;border-radius:14px;padding:28px 32px}'
            . 'code{background:#f1f5f2;padding:2px 6px;border-radius:5px;font-size:.92em}h1{font-size:20px;margin-top:0}</style></head><body>'
            . '<div class="box"><h1>Database unavailable</h1><p>The application could not reach its database.</p>'
            . '<p style="color:#5a6b60">' . htmlspecialchars($hint) . '</p>'
            . '<p style="color:#5a6b60;font-size:14px">Driver: <code>' . htmlspecialchars($driver) . '</code>. '
            . 'Set <code>SAEE_DEBUG=1</code> in <code>.env</code> to see the exact error.</p></div></body></html>';
        return;
    }
}

// Load application settings (safe when the database is not installed yet).
try {
    Settings::load();
} catch (Throwable $e) {
    if (APP_DEBUG) {
        error_log('Settings load failed: ' . $e->getMessage());
    }
}

$appConfig = Config::get('app');
if (!empty($appConfig['url'])) {
    \Saee\Lib\Url::setBase(rtrim((string) $appConfig['url'], '/'));
}
