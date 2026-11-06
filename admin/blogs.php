<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.blogs');

crud_page([
    "table" => "blogs", "title" => "Blog Posts", "singular" => "Post", "module" => "blogs",
    "order" => "id DESC", "search" => ["title", "excerpt"], "list" => ["featured_image", "title", "views", "status"],
    "fields" => [
        "title" => ["type" => "text", "label" => "Title", "required" => true, "max" => 180],
        "slug" => ["type" => "text", "label" => "Slug (auto)", "max" => 190],
        "category_id" => ["type" => "number", "label" => "Category ID", "default" => 1],
        "excerpt" => ["type" => "textarea", "label" => "Excerpt", "max" => 400],
        "content" => ["type" => "richtext", "label" => "Content", "required" => true],
        "featured_image" => ["type" => "image", "label" => "Featured image", "folder" => "blogs"],
        "author_name" => ["type" => "text", "label" => "Author", "max" => 100, "default" => "Saee Life Herbal"],
        "tags" => ["type" => "text", "label" => "Tags (comma separated)", "max" => 255],
        "meta_title" => ["type" => "text", "label" => "Meta title", "max" => 70],
        "meta_description" => ["type" => "textarea", "label" => "Meta description", "max" => 170],
        "is_featured" => ["type" => "checkbox", "label" => "Featured"],
        "published_at" => ["type" => "datetime", "label" => "Publish date"],
        "status" => ["type" => "select", "label" => "Status", "options" => ["published" => "Published", "draft" => "Draft"], "default" => "published"],
    ],
]);
