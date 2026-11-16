<?php
/**
 * Shared field definition for the Product Categories module.
 *
 * The list (admin/categories.php) and the editor (admin/category-form.php) are separate routes
 * now, but they describe the same table. Keeping the schema in one file means adding a field
 * cannot leave one of them out of date.
 *
 * @param bool $formOnly true on the editor route, false on the list route.
 */
declare(strict_types=1);

if (!function_exists('category_crud_config')) {
    function category_crud_config(bool $formOnly): array
    {
        // Parent choices as a real dropdown (a raw ID field is how wrong parents got saved before).
        $parentOptions = [0 => '— No parent (top level) —'];
        $existing = db()->select('SELECT id, name, parent_id FROM product_categories ORDER BY sort_order ASC, name ASC');
        $editingId = (int) ($_GET['id'] ?? 0);
        $walk = static function (int $parent, string $prefix = '') use (&$walk, $existing, $editingId, &$parentOptions): void {
            foreach ($existing as $row) {
                if ((int) ($row['parent_id'] ?? 0) !== $parent) {
                    continue;
                }
                // A category cannot be its own parent, nor sit under its own descendant.
                if ((int) $row['id'] === $editingId) {
                    continue;
                }
                $parentOptions[(int) $row['id']] = $prefix . (string) $row['name'];
                $walk((int) $row['id'], $prefix . '— ');
            }
        };
        $walk(0);

        $config = [
            "table" => "product_categories", "title" => "Product Categories", "singular" => "Category", "module" => "categories",
            "options" => ["parent_options" => $parentOptions],
            "order" => "sort_order ASC, id ASC", "search" => ["name", "slug"], "list" => ["image", "name", "parent_id", "sort_order", "status"],
            "fields" => [
                "name" => ["type" => "text", "label" => "Name", "required" => true, "max" => 120],
                "slug" => ["type" => "text", "label" => "Slug (auto)", "max" => 140],
                "parent_id" => ["type" => "select_from", "source" => "parent_options", "label" => "Parent category", "default" => 0, "zero_null" => true, "help" => "Leave empty for a top-level category. Sub-categories move with their parent."],
                "short_description" => ["type" => "textarea", "label" => "Short description", "max" => 300],
                "description" => ["type" => "richtext", "label" => "Full description"],
                "image" => ["type" => "image", "label" => "Image", "folder" => "categories"],
                "banner_image" => ["type" => "image", "label" => "Banner image", "folder" => "categories"],
                "icon" => ["type" => "text", "label" => "Icon class", "max" => 60, "help" => "Font Awesome class e.g. fa-solid fa-seedling"],
                "meta_title" => ["type" => "text", "label" => "Meta title", "max" => 70],
                "meta_description" => ["type" => "textarea", "label" => "Meta description", "max" => 170],
                "sort_order" => ["type" => "number", "label" => "Sort order", "default" => 0],
                "show_on_home" => ["type" => "checkbox", "label" => "Show on home"],
                "status" => ["type" => "select", "label" => "Status", "options" => [1 => "Active", 0 => "Inactive"], "default" => 1],
            ],
        ];

        if ($formOnly) {
            // Editor route: render only the form, and send saves back to the list.
            $config['form_only'] = true;
            $config['route'] = 'categories.php';
        } else {
            // List route: the editor lives elsewhere, so "New" and the pencil link out to it.
            $config['form_route'] = 'category-form.php';
        }

        return $config;
    }
}
