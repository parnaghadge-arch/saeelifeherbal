<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.gallery');

crud_page([
    "table" => "videos", "title" => "Videos", "singular" => "Video", "module" => "gallery",
    "order" => "sort_order ASC, id DESC", "search" => ["title", "category"], "list" => ["thumbnail", "title", "source_type", "status"],
    "fields" => [
        "title" => ["type" => "text", "label" => "Title", "required" => true, "max" => 150],
        "description" => ["type" => "textarea", "label" => "Description", "max" => 400],
        "source_type" => ["type" => "select", "label" => "Source", "options" => ["youtube" => "YouTube / embed URL", "local" => "Uploaded file"], "default" => "youtube"],
        "video_url" => ["type" => "text", "label" => "Embed URL", "max" => 255],
        "video_file" => ["type" => "file", "label" => "Video file (MP4)", "folder" => "videos"],
        "thumbnail" => ["type" => "image", "label" => "Thumbnail", "folder" => "videos"],
        "category" => ["type" => "text", "label" => "Category", "max" => 60, "default" => "general"],
        "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
        "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
    ],
]);
