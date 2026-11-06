<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.content');

crud_page([
    "table" => "faqs", "title" => "FAQs", "singular" => "FAQ", "module" => "content",
    "order" => "sort_order ASC, id ASC", "search" => ["question", "answer", "category"], "list" => ["question", "category", "sort_order", "status"],
    "fields" => [
        "category" => ["type" => "text", "label" => "Category", "max" => 60, "default" => "general"],
        "question" => ["type" => "text", "label" => "Question", "required" => true, "max" => 255],
        "answer" => ["type" => "richtext", "label" => "Answer", "required" => true],
        "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
        "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
    ],
]);
