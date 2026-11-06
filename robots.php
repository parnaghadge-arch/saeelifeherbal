<?php
/** Dynamic robots.txt. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: text/plain; charset=utf-8');
echo (new \Saee\Lib\SeoService(\Saee\Lib\Database::instance()))->robotsTxt();
