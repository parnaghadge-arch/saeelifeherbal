<?php
/**
 * Add / edit a single product category.
 *
 * Its own route, paired with the list on admin/categories.php. The field definitions come from
 * includes/category-config.php so the two routes can never disagree about the table.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
require __DIR__ . '/includes/category-config.php';
admin_require('categories.manage');

crud_page(category_crud_config(true));
