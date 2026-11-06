<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.navigation');

crud_page([
    "table" => "navigation_menus", "title" => "Navigation Menus", "singular" => "Menu Item", "module" => "navigation",
    "order" => "position ASC, sort_order ASC", "search" => ["label", "url"], "list" => ["label", "url", "position", "sort_order", "status"],
    "intro" => "Header, footer, quick links and mobile menus",
    "fields" => [
        "label" => ["type" => "text", "label" => "Label", "required" => true, "max" => 80],
        "url" => ["type" => "text", "label" => "URL", "required" => true, "max" => 200],
        "parent_id" => ["type" => "number", "label" => "Parent ID", "default" => 0, "zero_null" => true],
        "position" => ["type" => "select", "label" => "Position", "options" => ["header" => "Header", "footer" => "Footer", "quick" => "Quick links", "mobile" => "Mobile"], "default" => "header"],
        "icon" => ["type" => "text", "label" => "Icon class", "max" => 60],
        "target" => ["type" => "select", "label" => "Open in", "options" => ["_self" => "Same tab", "_blank" => "New tab"], "default" => "_self"],
        "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
        "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
    ],
]);
