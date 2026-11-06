<?php
/**
 * Website rendering helpers: layout, content queries and shared partials.
 */
declare(strict_types=1);

use Saee\Lib\Cart;
use Saee\Lib\CustomerAuth;
use Saee\Lib\Database;
use Saee\Lib\Notification;
use Saee\Lib\SeoService;
use Saee\Lib\Settings;
use Saee\Lib\Url;

if (!function_exists('db')) {
    function db(): Database
    {
        return Database::instance();
    }
}

/** Active slides for the homepage carousel. */
function get_sliders(): array
{
    $now = date('Y-m-d H:i:s');
    return db()->select(
        "SELECT * FROM homepage_sliders
         WHERE status = 1
           AND (publish_from IS NULL OR publish_from <= :now)
           AND (publish_to IS NULL OR publish_to >= :now)
         ORDER BY sort_order ASC, id ASC",
        ['now' => $now]
    );
}

function get_categories(bool $onlyHome = false, int $limit = 12): array
{
    $sql = "SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id AND p.status = 'active') AS product_count
            FROM product_categories c WHERE c.status = 1 AND c.parent_id IS NULL";
    if ($onlyHome) {
        $sql .= ' AND c.show_on_home = 1';
    }
    $sql .= ' ORDER BY c.sort_order ASC, c.id ASC LIMIT ' . (int) $limit;
    return db()->select($sql);
}

function get_subcategories(int $parentId): array
{
    return db()->select('SELECT * FROM product_categories WHERE parent_id = :p AND status = 1 ORDER BY sort_order', ['p' => $parentId]);
}

function get_featured_products(int $limit = 8): array
{
    return db()->select(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
         WHERE p.status = 'active' AND p.is_featured = 1
         ORDER BY p.sort_order ASC, p.id DESC LIMIT " . (int) $limit
    );
}

function get_products(array $options = []): array
{
    $sql = "SELECT p.*, c.name AS category_name, c.slug AS category_slug
            FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE p.status = 'active'";
    $params = [];
    if (!empty($options['category'])) {
        $sql .= ' AND (c.slug = :cat OR c.parent_id = (SELECT id FROM product_categories WHERE slug = :cat))';
        $params['cat'] = $options['category'];
    }
    if (!empty($options['search'])) {
        $sql .= ' AND (p.name LIKE :q OR p.short_description LIKE :q OR p.suitable_crops LIKE :q OR p.sku LIKE :q)';
        $params['q'] = '%' . $options['search'] . '%';
    }
    if (!empty($options['featured'])) {
        $sql .= ' AND p.is_featured = 1';
    }
    $sort = $options['sort'] ?? 'newest';
    $sql .= match ($sort) {
        'price_low' => ' ORDER BY p.price ASC',
        'price_high' => ' ORDER BY p.price DESC',
        'name' => ' ORDER BY p.name ASC',
        'popular' => ' ORDER BY p.views DESC, p.id DESC',
        default => ' ORDER BY p.is_featured DESC, p.sort_order ASC, p.id DESC',
    };
    if (!empty($options['limit'])) {
        $sql .= ' LIMIT ' . (int) $options['limit'] . ' OFFSET ' . (int) ($options['offset'] ?? 0);
    }
    return db()->select($sql, $params);
}

function count_products(array $options = []): int
{
    $sql = "SELECT COUNT(*) FROM products p LEFT JOIN product_categories c ON c.id = p.category_id WHERE p.status = 'active'";
    $params = [];
    if (!empty($options['category'])) {
        $sql .= ' AND (c.slug = :cat OR c.parent_id = (SELECT id FROM product_categories WHERE slug = :cat))';
        $params['cat'] = $options['category'];
    }
    if (!empty($options['search'])) {
        $sql .= ' AND (p.name LIKE :q OR p.short_description LIKE :q OR p.suitable_crops LIKE :q OR p.sku LIKE :q)';
        $params['q'] = '%' . $options['search'] . '%';
    }
    return (int) db()->value($sql, $params);
}

function get_product_by_slug(string $slug): ?array
{
    return db()->first(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug
         FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
         WHERE p.slug = :s AND p.status = 'active'",
        ['s' => $slug]
    );
}

function get_product_images(int $productId): array
{
    return db()->select('SELECT * FROM product_images WHERE product_id = :p ORDER BY sort_order ASC, id ASC', ['p' => $productId]);
}

function get_product_videos(int $productId): array
{
    return db()->select('SELECT * FROM product_videos WHERE product_id = :p ORDER BY sort_order ASC, id ASC', ['p' => $productId]);
}

function get_related_products(int $productId, ?int $categoryId, int $limit = 4): array
{
    $sql = "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
            WHERE p.status = 'active' AND p.id <> :id";
    $params = ['id' => $productId];
    if ($categoryId) {
        $sql .= ' AND p.category_id = :cat';
        $params['cat'] = $categoryId;
    }
    $sql .= ' ORDER BY p.is_featured DESC, RANDOM_LIMIT';
    $sql = str_replace('RANDOM_LIMIT', db()->driver() === 'sqlite' ? 'RANDOM()' : 'RAND()', $sql) . ' LIMIT ' . (int) $limit;
    return db()->select($sql, $params);
}

function get_blogs(array $options = []): array
{
    $sql = "SELECT b.*, c.name AS category_name, c.slug AS category_slug FROM blogs b
            LEFT JOIN blog_categories c ON c.id = b.category_id WHERE b.status = 'published'";
    $params = [];
    if (!empty($options['category'])) {
        $sql .= ' AND c.slug = :cat';
        $params['cat'] = $options['category'];
    }
    if (!empty($options['search'])) {
        $sql .= ' AND (b.title LIKE :q OR b.excerpt LIKE :q)';
        $params['q'] = '%' . $options['search'] . '%';
    }
    $sql .= ' ORDER BY b.published_at DESC, b.id DESC';
    if (!empty($options['limit'])) {
        $sql .= ' LIMIT ' . (int) $options['limit'] . ' OFFSET ' . (int) ($options['offset'] ?? 0);
    }
    return db()->select($sql, $params);
}

function count_blogs(array $options = []): int
{
    $sql = 'SELECT COUNT(*) FROM blogs b LEFT JOIN blog_categories c ON c.id = b.category_id WHERE b.status = \'published\'';
    $params = [];
    if (!empty($options['category'])) {
        $sql .= ' AND c.slug = :cat';
        $params['cat'] = $options['category'];
    }
    if (!empty($options['search'])) {
        $sql .= ' AND (b.title LIKE :q OR b.excerpt LIKE :q)';
        $params['q'] = '%' . $options['search'] . '%';
    }
    return (int) db()->value($sql, $params);
}

function get_blog_by_slug(string $slug): ?array
{
    return db()->first(
        "SELECT b.*, c.name AS category_name, c.slug AS category_slug FROM blogs b
         LEFT JOIN blog_categories c ON c.id = b.category_id
         WHERE b.slug = :s AND b.status = 'published'",
        ['s' => $slug]
    );
}

function get_blog_categories(): array
{
    return db()->select('SELECT * FROM blog_categories WHERE status = 1 ORDER BY sort_order, name');
}

function get_gallery(?string $category = null): array
{
    $sql = 'SELECT * FROM gallery_items WHERE status = 1';
    $params = [];
    if ($category) {
        $sql .= ' AND category = :c';
        $params['c'] = $category;
    }
    $sql .= ' ORDER BY sort_order, id DESC';
    return db()->select($sql, $params);
}

function get_gallery_categories(): array
{
    return db()->column('SELECT DISTINCT category FROM gallery_items WHERE status = 1 ORDER BY category');
}

function get_videos(?string $category = null): array
{
    $sql = 'SELECT * FROM videos WHERE status = 1';
    $params = [];
    if ($category) {
        $sql .= ' AND category = :c';
        $params['c'] = $category;
    }
    $sql .= ' ORDER BY sort_order, id DESC';
    return db()->select($sql, $params);
}

function get_testimonials(int $limit = 6): array
{
    return db()->select('SELECT * FROM testimonials WHERE status = 1 ORDER BY sort_order, id DESC LIMIT ' . (int) $limit);
}

function get_faqs(?string $category = null): array
{
    $sql = 'SELECT * FROM faqs WHERE status = 1';
    $params = [];
    if ($category) {
        $sql .= ' AND category = :c';
        $params['c'] = $category;
    }
    $sql .= ' ORDER BY sort_order, id';
    return db()->select($sql, $params);
}

function get_faq_categories(): array
{
    return db()->column('SELECT DISTINCT category FROM faqs WHERE status = 1 ORDER BY category');
}

function get_social_links(): array
{
    return db()->select('SELECT * FROM social_media WHERE status = 1 ORDER BY sort_order, id');
}

function get_menu(string $position = 'header'): array
{
    $rows = db()->select(
        'SELECT * FROM navigation_menus WHERE position = :p AND status = 1 ORDER BY sort_order ASC, id ASC',
        ['p' => $position]
    );
    $tree = [];
    foreach ($rows as $row) {
        if ($row['parent_id'] === null) {
            $row['children'] = [];
            $tree[$row['id']] = $row;
        }
    }
    foreach ($rows as $row) {
        if ($row['parent_id'] !== null && isset($tree[$row['parent_id']])) {
            $tree[$row['parent_id']]['children'][] = $row;
        }
    }
    return array_values($tree);
}

function menu_url(string $url): string
{
    if ($url === '' || $url === '#') {
        return '#';
    }
    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }
    // "home" is the site root; everything else is a clean route (/about-us, /our-products).
    $url = ltrim($url, '/');
    if ($url === 'home' || $url === 'index' || $url === 'index.php') {
        return Url::base() . '/';
    }
    return Url::base() . '/' . Url::pretty($url);
}

function get_page_by_slug(string $slug): ?array
{
    return db()->first("SELECT * FROM website_pages WHERE slug = :s AND status = 'published'", ['s' => $slug]);
}

/** Website statistics for the "trust" band, taken from real records. */
function site_stats(): array
{
    $cache = [];
    $cache['members'] = (int) db()->value("SELECT COUNT(*) FROM members WHERE is_company = 0 AND status = 'active'");
    $cache['products'] = (int) db()->value("SELECT COUNT(*) FROM products WHERE status = 'active'");
    $cache['orders'] = (int) db()->value('SELECT COUNT(*) FROM orders');
    $cache['districts'] = (int) db()->value('SELECT COUNT(DISTINCT district) FROM member_profiles WHERE district IS NOT NULL');
    return $cache;
}

function cart_count(): int
{
    static $count = null;
    if ($count === null) {
        try {
            $count = (new Cart(db()))->itemCount();
        } catch (Throwable) {
            $count = 0;
        }
    }
    return $count;
}

function wishlist_count(): int
{
    try {
        return count((new Cart(db()))->wishlistIds());
    } catch (Throwable) {
        return 0;
    }
}

function current_route(): string
{
    $path = trim(\Saee\Lib\Request::uri(), '/');
    return $path === '' ? 'home' : $path;
}

function page_meta(array $meta = []): string
{
    return (new SeoService(db()))->meta($meta);
}

/** Render flash messages once. */
/**
 * Access token for a guest order. Order ids are sequential, so nothing that exposes a
 * customer name, phone, address or invoice may be reachable by id alone.
 */
function order_access_token(array $order): string
{
    return substr(hash_hmac('sha256', (string) $order['order_number'] . '|' . (string) $order['id'], (string) \Saee\Lib\Config::get('security.encryption_key', 'saee-order-token')), 0, 20);
}

/**
 * Can the current visitor (or session) open this order?
 * Customers may open their own orders, admins may open any, and a guest may open an
 * order only when the confirmation link carried the matching token or the order was
 * just placed in this session.
 */
function can_view_order(array $order): bool
{
    if (\Saee\Lib\AdminAuth::check()) {
        return true;
    }
    $customerId = \Saee\Lib\CustomerAuth::id();
    if ($customerId !== null && (int) $order['customer_id'] === $customerId) {
        return true;
    }
    $memberId = \Saee\Lib\MemberAuth::id();
    if ($memberId !== null && (int) $order['member_id'] === $memberId) {
        return true;
    }
    $placed = (array) \Saee\Lib\Session::get('placed_orders', []);
    if (in_array((int) $order['id'], array_map('intval', $placed), true)) {
        return true;
    }
    $token = (string) \Saee\Lib\Request::query('token', '');
    return $token !== '' && hash_equals(order_access_token($order), $token);
}

function order_url(array $order): string
{
    return Url::to('order-confirmation.php?order=' . (int) $order['id'] . '&token=' . order_access_token($order));
}

function render_flash(): string
{
    $out = '';
    foreach (\Saee\Lib\Session::pullFlash() as $flash) {
        $type = in_array($flash['type'], ['success', 'danger', 'warning', 'info'], true) ? $flash['type'] : 'info';
        $out .= '<div class="alert alert-' . $type . ' alert-dismissible fade show" role="alert" data-auto-dismiss>' . e($flash['message'])
            . '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button></div>';
    }
    return $out;
}

/** Truncate helper alias. */
function excerpt(?string $text, int $len = 150): string
{
    return e(truncate_text($text, $len));
}

/** Format a video URL into an embeddable URL. */
function video_embed_url(array $video): string
{
    $url = (string) ($video['video_url'] ?? '');
    if ($video['source_type'] === 'youtube') {
        if (preg_match('#(?:youtu\.be/|v=|embed/)([A-Za-z0-9_-]{6,})#', $url, $m)) {
            return 'https://www.youtube-nocookie.com/embed/' . $m[1];
        }
        return $url;
    }
    if ($video['source_type'] === 'vimeo') {
        if (preg_match('#vimeo\.com/(\d+)#', $url, $m)) {
            return 'https://player.vimeo.com/video/' . $m[1];
        }
        return $url;
    }
    return Url::media($video['video_file'] ?? $url);
}

function product_discount(array $product): int
{
    $mrp = (float) $product['mrp'];
    $price = (float) $product['price'];
    if ($mrp <= 0 || $price >= $mrp) {
        return 0;
    }
    return (int) round((($mrp - $price) / $mrp) * 100);
}

/** Share links for social sharing widgets. */
function share_links(string $url, string $title): array
{
    $u = urlencode($url);
    $t = urlencode($title);
    return [
        'whatsapp' => "https://wa.me/?text=$t%20$u",
        'facebook' => "https://www.facebook.com/sharer/sharer.php?u=$u",
        'x' => "https://twitter.com/intent/tweet?url=$u&text=$t",
        'linkedin' => "https://www.linkedin.com/sharing/share-offsite/?url=$u",
        'telegram' => "https://t.me/share/url?url=$u&text=$t",
    ];
}

/** Points/breadcrumb renderer used by inner pages. */
function breadcrumbs(array $crumbs): string
{
    $html = '<ul class="breadcrumb-saee"><li><a href="' . Url::base() . '/">Home</a></li>';
    foreach ($crumbs as $label => $url) {
        $html .= $url ? '<li><a href="' . e(menu_url($url)) . '">' . e($label) . '</a></li>' : '<li>' . e($label) . '</li>';
    }
    return $html . '</ul>';
}

/** Standard page hero used across inner pages. */
function page_hero(string $title, string $subtitle = '', array $crumbs = [], ?string $image = null): string
{
    $bg = $image ? ' style="background-image:linear-gradient(120deg, rgba(20,83,45,.92), rgba(36,122,60,.78)), url(' . e(Url::media($image)) . ');background-size:cover;background-position:center"' : '';
    $html = '<section class="page-hero"' . $bg . '><div class="container position-relative">';
    $html .= '<h1>' . e($title) . '</h1>';
    if ($subtitle !== '') {
        $html .= '<p>' . e($subtitle) . '</p>';
    }
    if ($crumbs !== []) {
        $html .= breadcrumbs($crumbs);
    }
    return $html . '</div></section>';
}

/** Server side form error bag renderer. */
function field_error(array $errors, string $field): string
{
    if (!empty($errors[$field])) {
        return '<div class="invalid-feedback d-block">' . e($errors[$field][0]) . '</div>';
    }
    return '';
}

function invalid_class(array $errors, string $field): string
{
    return !empty($errors[$field]) ? ' is-invalid' : '';
}

/** Human readable payment method. */
function payment_label(?string $method): string
{
    return PAYMENT_METHODS[$method ?? ''] ?? ucfirst((string) $method);
}
