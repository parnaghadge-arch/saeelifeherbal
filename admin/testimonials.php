<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.content');

crud_page([
    "table" => "testimonials", "title" => "Testimonials", "singular" => "Testimonial", "module" => "content",
    "order" => "sort_order ASC, id DESC", "search" => ["name", "location"], "list" => ["photo", "name", "location", "rating", "status"],
    "fields" => [
        "name" => ["type" => "text", "label" => "Farmer name", "required" => true, "max" => 120],
        "designation" => ["type" => "text", "label" => "Designation", "max" => 120],
        "location" => ["type" => "text", "label" => "Location", "max" => 120],
        "message" => ["type" => "textarea", "label" => "Testimonial", "required" => true, "max" => 700],
        "photo" => ["type" => "image", "label" => "Photo", "folder" => "testimonials"],
        "rating" => ["type" => "number", "label" => "Rating (1-5)", "default" => 5],
        "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
        "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
    ],
]);
