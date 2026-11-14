<?php
/**
 * Saee Life Herbal - Application configuration
 * Values here are defaults; server-specific values live in config/config.php
 */

declare(strict_types=1);

if (!defined('APP_ROOT')) {
    define('APP_ROOT', dirname(__DIR__));
}

require_once APP_ROOT . '/includes/env.php';

define('APP_NAME', 'Saee Life Herbal');
define('APP_VERSION', '1.4.0');
define('APP_ENV', getenv('SAEE_ENV') ?: 'production');
define('APP_DEBUG', filter_var(getenv('SAEE_DEBUG') ?: (APP_ENV === 'local'), FILTER_VALIDATE_BOOLEAN));

define('DS', DIRECTORY_SEPARATOR);
define('CONFIG_PATH', APP_ROOT . DS . 'config');
define('INCLUDES_PATH', APP_ROOT . DS . 'includes');
define('LIB_PATH', APP_ROOT . DS . 'lib');
define('UPLOAD_PATH', APP_ROOT . DS . 'uploads');
define('STORAGE_PATH', APP_ROOT . DS . 'storage');
define('LOG_PATH', STORAGE_PATH . DS . 'logs');
define('BACKUP_PATH', STORAGE_PATH . DS . 'backups');

define('SESSION_NAME', 'SAEE_SESSID');
define('SESSION_LIFETIME', 7200);          // 2 hours idle timeout
define('REMEMBER_LIFETIME', 60 * 60 * 24 * 30);
define('LOGIN_MAX_ATTEMPTS', 5);
define('LOGIN_LOCK_MINUTES', 15);
define('PASSWORD_MIN_LENGTH', 8);
define('MAX_UPLOAD_MB', 12);

define('DEFAULT_TIMEZONE', 'Asia/Kolkata');
define('CURRENCY', 'INR');
// Written as the \u{20B9} escape, never the literal glyph: a config file saved or uploaded
// with the wrong encoding used to turn the rupee sign into stray digits (262145), which
// then appeared inside every price on the site.
define('CURRENCY_SYMBOL', "\u{20B9}");
// The bottom of the company chain (SAEE00001 -> SAEE00002 -> SAEE00003). Every partner who
// registers without naming a sponsor is attached here, so this must be the LAST account in
// the chain rather than the root — see lib/CompanyStructure.php.
define('DEFAULT_COMPANY_SPONSOR_ID', 'SAEE00003');

date_default_timezone_set(DEFAULT_TIMEZONE);

// Error handling -------------------------------------------------------------
error_reporting(E_ALL);
ini_set('display_errors', APP_DEBUG ? '1' : '0');
ini_set('log_errors', '1');
if (!is_dir(LOG_PATH)) {
    @mkdir(LOG_PATH, 0775, true);
}
ini_set('error_log', LOG_PATH . DS . 'php-error.log');

require_once LIB_PATH . DS . 'Autoloader.php';
