<?php
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
admin_require('cms.blogs');

crud_page([
    'table' => 'blog_categories', 'title' => 'Blog Categories', 'singular' => 'Blog Category', 'module' => 'blogs',
    'order' => 'id ASC', 'search' => ['name'], 'list' => ['name', 'slug', 'status'],
    'fields' => [
        'name' => ['type' => 'text', 'label' => 'Name', 'required' => true, 'max' => 120],
        'slug' => ['type' => 'text', 'label' => 'Slug (auto)', 'max' => 140],
        'description' => ['type' => 'textarea', 'label' => 'Description', 'max' => 300],
        'status' => ['type' => 'select', 'label' => 'Status', 'options' => [1 => 'Active', 0 => 'Inactive'], 'default' => 1],
    ],
]);
