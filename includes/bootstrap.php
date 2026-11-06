<?php
/**
 * Application bootstrap - shared by the website, admin panel, member portal and APIs.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/config/app.php';
require_once CONFIG_PATH . '/constants.php';
require_once LIB_PATH . '/Helpers.php';

use Saee\Lib\Config;
use Saee\Lib\Settings;

// Friendly error page for uncaught exceptions in production.
set_exception_handler(static function (Throwable $e): void {
    error_log('[uncaught] ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine());
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
