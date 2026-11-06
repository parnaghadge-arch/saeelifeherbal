<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('settings.manage');

crud_page([
    "table" => "social_media", "title" => "Social Links", "singular" => "Social Link", "module" => "settings",
    "order" => "sort_order ASC", "search" => ["platform", "url"], "list" => ["platform", "url", "sort_order", "status"],
    "fields" => [
        "platform" => ["type" => "text", "label" => "Platform", "required" => true, "max" => 60],
        "display_name" => ["type" => "text", "label" => "Display name", "max" => 60],
        "url" => ["type" => "text", "label" => "URL", "required" => true, "max" => 200],
        "icon" => ["type" => "text", "label" => "Icon class", "max" => 60, "default" => "fa-brands fa-facebook"],
        "open_new_tab" => ["type" => "checkbox", "label" => "Open in new tab", "default" => 1],
        "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
        "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
    ],
]);
