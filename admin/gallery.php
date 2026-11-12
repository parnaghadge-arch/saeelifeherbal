<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.gallery');

crud_page([
    "table" => "gallery_items", "title" => "Gallery Images", "singular" => "Image", "module" => "gallery",
    "order" => "sort_order ASC, id DESC", "search" => ["title", "category"], "list" => ["image", "title", "category", "sort_order", "status"],
    "fields" => [
        "image" => ["type" => "image", "label" => "Image", "required" => true, "folder" => "gallery"],
        "title" => ["type" => "text", "label" => "Title", "max" => 150],
        "category" => ["type" => "text", "label" => "Category", "max" => 60, "default" => "general"],
        "description" => ["type" => "textarea", "label" => "Description", "max" => 300],
        "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
        "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
    ],
]);
