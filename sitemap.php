<?php
/** XML sitemap generated from live content. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';

header('Content-Type: application/xml; charset=utf-8');
echo (new \Saee\Lib\SeoService(\Saee\Lib\Database::instance()))->sitemapXml();
