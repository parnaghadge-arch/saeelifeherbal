<?php
/**
 * Product Categories - the list.
 *
 * Adding and editing a category happens on admin/category-form.php, so opening the editor no
 * longer redraws the table you are standing on.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
require __DIR__ . '/includes/crud.php';
require __DIR__ . '/includes/category-config.php';
admin_require('categories.manage');

crud_page(category_crud_config(false));
