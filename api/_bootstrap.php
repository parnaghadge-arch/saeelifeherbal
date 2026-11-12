<?php
/**
 * Shared bootstrap for AJAX/REST endpoints.
 * Enforces POST + CSRF for state changing calls and returns JSON only.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/includes/bootstrap.php';

use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\Security;

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

if (!function_exists('api_json_failure')) {
    /**
     * Emit a JSON error for an exception or fatal instead of the HTML error page.
     *
     * An API caller that receives our HTML error page cannot parse it: the JavaScript sees a
     * 500 with an HTML body and reports something generic, or throws a JSON parse error. The
     * reference is still returned so the visitor can quote it and the log can be searched.
     */
    function api_json_failure(?\Throwable $e, string $fallbackDetail = ''): void
    {
        if (defined('SAEE_API_RENDERED')) {
            return;
        }
        define('SAEE_API_RENDERED', true);

        // Drop any half-written response, headers included.
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
        }

        $ref = defined('SAEE_ERROR_REF') ? SAEE_ERROR_REF : '';
        $GLOBALS['saee_error_ref'] = $ref;
        $detail = $e !== null
            ? get_class($e) . ': ' . $e->getMessage() . ' in ' . $e->getFile() . ':' . $e->getLine()
            : $fallbackDetail;
        if (function_exists('saee_log_failure')) {
            saee_log_failure($detail);
        }

        $payload = [
            'ok' => false,
            'error' => 'Something went wrong on our side. Please try again.',
            'reference' => $ref,
        ];
        if (defined('APP_DEBUG') && APP_DEBUG) {
            $payload['detail'] = $detail;
        }
        echo json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        exit;
    }
}

set_exception_handler(static function (\Throwable $e): void {
    api_json_failure($e);
});

register_shutdown_function(static function (): void {
    $error = error_get_last();
    if ($error === null) {
        return;
    }
    $fatal = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;
    if ((($error['type'] ?? 0) & $fatal) === 0) {
        return;
    }
    api_json_failure(null, 'Fatal error: ' . ($error['message'] ?? 'unknown')
        . ' in ' . ($error['file'] ?? '?') . ':' . ($error['line'] ?? 0));
});

if (!function_exists('api_require_post')) {
    /** Require POST with a valid CSRF token. */
    function api_require_post(): void
    {
        if (!Request::isPost()) {
            Response::error('Method not allowed.', [], 405);
        }
        Security::verifyCsrf(null, true);
    }
}

if (!function_exists('api_rate_limit')) {
    /**
     * Very small fixed-window rate limiter for public endpoints (per IP).
     */
    function api_rate_limit(string $scope, int $maxPerMinute = 30): void
    {
        try {
            $db = \Saee\Lib\Database::instance();
            $key = 'rl:' . $scope . ':' . Request::ip();
            $row = $db->first('SELECT * FROM login_attempts WHERE scope = :s AND identifier = :i', ['s' => 'rate', 'i' => $key]);
            $now = date('Y-m-d H:i:s');
            if (!$row) {
                $db->insert('login_attempts', ['scope' => 'rate', 'identifier' => $key, 'attempts' => 1, 'last_attempt_at' => $now, 'created_at' => $now]);
                return;
            }
            if (strtotime((string) $row['last_attempt_at']) < time() - 60) {
                $db->update('login_attempts', ['attempts' => 1, 'last_attempt_at' => $now], 'id = :id', ['id' => $row['id']]);
                return;
            }
            $attempts = (int) $row['attempts'] + 1;
            $db->update('login_attempts', ['attempts' => $attempts, 'last_attempt_at' => $now], 'id = :id', ['id' => $row['id']]);
            if ($attempts > $maxPerMinute) {
                Response::error('Too many requests. Please slow down.', [], 429);
            }
        } catch (\Throwable) {
            // never block traffic on limiter failure
        }
    }
}
