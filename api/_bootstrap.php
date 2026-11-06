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
