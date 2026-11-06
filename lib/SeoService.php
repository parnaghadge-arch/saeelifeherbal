<?php
/**
 * SEO services: meta tags, structured data, sitemap generation and robots.txt.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class SeoService
{
    public function __construct(private Database $db)
    {
    }

    /** Meta tags for a page. */
    public function meta(array $meta): string
    {
        $siteName = (string) Settings::get('site_name', APP_NAME);
        $title = trim((string) ($meta['title'] ?? ''));
        $suffix = (string) Settings::get('meta_title_suffix', $siteName);
        $fullTitle = $title !== '' && $suffix !== '' && !str_contains($title, $suffix) ? $title . ' | ' . $suffix : ($title ?: $siteName);
        $description = trim((string) ($meta['description'] ?? Settings::get('meta_description_default', '')));
        $keywords = trim((string) ($meta['keywords'] ?? Settings::get('meta_keywords_default', '')));
        $canonical = (string) ($meta['canonical'] ?? Url::canonical());
        $image = (string) ($meta['image'] ?? Settings::get('og_default_image', 'assets/images/logo-square.png'));
        $imageUrl = Url::media($image);
        $robots = (string) ($meta['robots'] ?? 'index,follow');
        $type = (string) ($meta['type'] ?? 'website');

        $html = "\n<title>" . e($fullTitle) . "</title>\n";
        $html .= '<meta name="description" content="' . e($description) . '">' . "\n";
        if ($keywords !== '') {
            $html .= '<meta name="keywords" content="' . e($keywords) . '">' . "\n";
        }
        $html .= '<meta name="robots" content="' . e($robots) . '">' . "\n";
        $html .= '<link rel="canonical" href="' . e($canonical) . '">' . "\n";
        $html .= '<meta property="og:site_name" content="' . e($siteName) . '">' . "\n";
        $html .= '<meta property="og:type" content="' . e($type) . '">' . "\n";
        $html .= '<meta property="og:title" content="' . e($fullTitle) . '">' . "\n";
        $html .= '<meta property="og:description" content="' . e($description) . '">' . "\n";
        $html .= '<meta property="og:url" content="' . e($canonical) . '">' . "\n";
        $html .= '<meta property="og:image" content="' . e($imageUrl) . '">' . "\n";
        $html .= '<meta name="twitter:card" content="summary_large_image">' . "\n";
        $html .= '<meta name="twitter:title" content="' . e($fullTitle) . '">' . "\n";
        $html .= '<meta name="twitter:description" content="' . e($description) . '">' . "\n";
        $html .= '<meta name="twitter:image" content="' . e($imageUrl) . '">' . "\n";
        $verification = (string) Settings::get('google_site_verification', '');
        if ($verification !== '') {
            $html .= '<meta name="google-site-verification" content="' . e($verification) . '">' . "\n";
        }
        return $html;
    }

    public function organizationSchema(): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Organization',
            'name' => (string) Settings::get('site_name', APP_NAME),
            'url' => Url::base(),
            'logo' => Url::media((string) Settings::get('logo_header', 'assets/images/logo.png')),
            'description' => (string) Settings::get('site_description', ''),
            'email' => (string) Settings::get('contact_email', ''),
            'telephone' => (string) Settings::get('contact_phone', ''),
            'address' => [
                '@type' => 'PostalAddress',
                'streetAddress' => str_replace("\n", ', ', (string) Settings::get('company_address', '')),
                'addressCountry' => 'IN',
            ],
            'sameAs' => $this->db->column('SELECT url FROM social_media WHERE status = 1 ORDER BY sort_order'),
        ];
        return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    public function productSchema(array $product, ?array $category = null): string
    {
        $data = [
            '@context' => 'https://schema.org',
            '@type' => 'Product',
            'name' => $product['name'],
            'description' => truncate_text($product['short_description'] ?: $product['description'], 300),
            'sku' => $product['sku'] ?? '',
            'image' => array_values(array_filter(array_map(
                static fn ($p) => Url::media($p),
                array_merge([$product['featured_image']], $this->db->column('SELECT image_path FROM product_images WHERE product_id = :p ORDER BY sort_order', ['p' => $product['id']]))
            ))),
            'brand' => ['@type' => 'Brand', 'name' => (string) Settings::get('site_name', APP_NAME)],
            'offers' => [
                '@type' => 'Offer',
                'priceCurrency' => 'INR',
                'price' => number_format((float) $product['price'], 2, '.', ''),
                'availability' => (int) $product['stock_quantity'] > 0 ? 'https://schema.org/InStock' : 'https://schema.org/OutOfStock',
                'url' => Url::to('product-details.php?slug=' . $product['slug']),
            ],
        ];
        if ($category) {
            $data['category'] = $category['name'];
        }
        return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    public function breadcrumbSchema(array $crumbs): string
    {
        $items = [];
        $position = 1;
        foreach ($crumbs as $label => $url) {
            $items[] = [
                '@type' => 'ListItem',
                'position' => $position++,
                'name' => $label,
                'item' => $url,
            ];
        }
        $data = ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items];
        return '<script type="application/ld+json">' . json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) . '</script>';
    }

    public function sitemapXml(): string
    {
        $base = Url::base();
        $now = date('c');
        $urls = [
            ['loc' => $base . '/', 'priority' => '1.0', 'changefreq' => 'daily'],
        ];
        foreach (['about-us', 'our-products', 'product-categories', 'agriculture-solutions', 'blogs', 'gallery', 'videos', 'faqs', 'contact-us', 'mlm-business-opportunity', 'farmer-registration', 'privacy-policy', 'terms-conditions', 'refund-return-policy', 'shipping-policy', 'disclaimer'] as $path) {
            $urls[] = ['loc' => $base . '/' . $path, 'priority' => '0.7', 'changefreq' => 'weekly'];
        }
        foreach ($this->db->select("SELECT slug, updated_at FROM website_pages WHERE status = 'published' AND visibility = 'public'") as $page) {
            $urls[] = ['loc' => $base . '/' . $page['slug'], 'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => $page['updated_at']];
        }
        foreach ($this->db->select("SELECT slug, updated_at FROM product_categories WHERE status = 1") as $cat) {
            $urls[] = ['loc' => $base . '/products.php?category=' . $cat['slug'], 'priority' => '0.8', 'changefreq' => 'weekly', 'lastmod' => $cat['updated_at']];
        }
        foreach ($this->db->select("SELECT slug, updated_at FROM products WHERE status = 'active'") as $product) {
            $urls[] = ['loc' => $base . '/product-details.php?slug=' . $product['slug'], 'priority' => '0.8', 'changefreq' => 'weekly', 'lastmod' => $product['updated_at']];
        }
        foreach ($this->db->select("SELECT slug, updated_at FROM blogs WHERE status = 'published'") as $blog) {
            $urls[] = ['loc' => $base . '/blog-details.php?slug=' . $blog['slug'], 'priority' => '0.6', 'changefreq' => 'monthly', 'lastmod' => $blog['updated_at']];
        }
        $xml = '<?xml version="1.0" encoding="UTF-8"?>' . "\n" . '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
        foreach ($urls as $url) {
            $xml .= "  <url>\n";
            $xml .= '    <loc>' . htmlspecialchars($url['loc'], ENT_XML1) . "</loc>\n";
            $xml .= '    <lastmod>' . ($url['lastmod'] ?? $now) . "</lastmod>\n";
            $xml .= '    <changefreq>' . $url['changefreq'] . "</changefreq>\n";
            $xml .= '    <priority>' . $url['priority'] . "</priority>\n";
            $xml .= "  </url>\n";
        }
        $xml .= '</urlset>';
        return $xml;
    }

    public function robotsTxt(): string
    {
        $custom = (string) Settings::get('robots_txt', '');
        $sitemap = 'Sitemap: ' . Url::base() . '/sitemap.xml';
        return ($custom !== '' ? $custom : "User-agent: *\nAllow: /\nDisallow: /admin\nDisallow: /member\nDisallow: /api\n") . "\n\n" . $sitemap . "\n";
    }
}
