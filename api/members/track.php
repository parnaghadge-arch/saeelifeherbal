<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\Database;
use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\Session;

if (!Request::isPost()) {
    Response::error('Method not allowed.', [], 405);
}
// Lightweight page view analytics: one record per session per page per 30 minutes.
$url = (string) Request::input('url', '/');
if (!str_starts_with($url, '/') || strlen($url) > 200) {
    $url = '/';
}
$sessionKey = 'pv_' . md5($url);
$last = (int) Session::get($sessionKey, 0);
if (time() - $last < 1800) {
    Response::success('Already tracked');
}
Session::set($sessionKey, time());
try {
    Database::instance()->insert('page_views', [
        'page_url' => substr($url, 0, 255),
        'visitor_hash' => hash('sha256', Request::ip() . '|' . Request::userAgent() . '|' . date('Y-m-d')),
        'ip_address' => Request::ip(),
        'user_agent' => Request::userAgent(),
        'referrer' => isset($_SERVER['HTTP_REFERER']) ? substr((string) $_SERVER['HTTP_REFERER'], 0, 255) : null,
        'created_at' => date('Y-m-d H:i:s'),
    ]);
} catch (\Throwable) {
}
Response::success('Tracked');
