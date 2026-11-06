<?php
/** Product detail page with gallery, videos, specifications and structured data. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Cart;
use Saee\Lib\CustomerAuth;
use Saee\Lib\SeoService;
use Saee\Lib\Url;

$slug = (string) \Saee\Lib\Request::query('slug', '');
$product = $slug !== '' ? get_product_by_slug($slug) : null;

if (!$product) {
    http_response_code(404);
    $pageTitle = 'Product not found';
    require INCLUDES_PATH . '/header.php';
    echo '<section class="section"><div class="container text-center py-5"><h1 class="h2">Product not found</h1><p class="text-muted">This product may have been removed or renamed.</p><a class="btn btn-primary" href="' . e(Url::to('our-products')) . '">Browse products</a></div></section>';
    require INCLUDES_PATH . '/footer.php';
    exit;
}

db()->execute('UPDATE products SET views = views + 1 WHERE id = :id', ['id' => $product['id']]);

$images = get_product_images((int) $product['id']);
if ($images === [] && $product['featured_image']) {
    $images = [['image_path' => $product['featured_image'], 'alt_text' => $product['name']]];
}
$videos = get_product_videos((int) $product['id']);
$related = get_related_products((int) $product['id'], $product['category_id'] ? (int) $product['category_id'] : null, 4);
$discount = product_discount($product);
$inWishlist = in_array((int) $product['id'], (new Cart(db()))->wishlistIds(), true);
$stock = (int) $product['stock_quantity'];

$pageTitle = $product['meta_title'] ?: $product['name'];
$pageDescription = $product['meta_description'] ?: truncate_text($product['short_description'] ?: $product['description'], 160);
$pageImage = $product['featured_image'];
$extraScripts = (new SeoService(db()))->productSchema($product, ['name' => $product['category_name'] ?? ''])
    . (new SeoService(db()))->breadcrumbSchema(['Home' => Url::base() . '/', 'Products' => Url::to('our-products'), $product['name'] => product_url((string) $product['slug'])]);

require INCLUDES_PATH . '/header.php';
echo page_hero($product['name'], $product['category_name'] ?? '', ['Products' => 'our-products', ($product['category_name'] ?? 'Category') => 'products.php?category=' . urlencode((string) ($product['category_slug'] ?? '')), $product['name'] => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-4 g-lg-5">
      <div class="col-lg-6">
        <div class="gallery-main">
          <img data-gallery-main src="<?= e(Url::media($images[0]['image_path'] ?? '')) ?>" alt="<?= e($product['name']) ?>" fetchpriority="high">
        </div>
        <?php if (count($images) > 1): ?>
          <div class="gallery-thumbs">
            <?php foreach ($images as $i => $img): ?>
              <button type="button" class="<?= $i === 0 ? 'active' : '' ?>" data-gallery-thumb="<?= e(Url::media($img['image_path'])) ?>" aria-label="View image <?= $i + 1 ?>">
                <img src="<?= e(Url::media($img['image_path'])) ?>" alt="<?= e($img['alt_text'] ?: $product['name']) ?>" loading="lazy">
              </button>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
        <?php if ($videos !== []): ?>
          <div class="mt-3">
            <?php foreach ($videos as $video): ?>
              <div class="video-frame rounded-3 overflow-hidden mb-2">
                <?php if ($video['source_type'] === 'local' && $video['video_file']): ?>
                  <video controls preload="metadata" poster="<?= e(Url::media($video['thumbnail'] ?? '')) ?>">
                    <source src="<?= e(Url::media($video['video_file'])) ?>">
                  </video>
                <?php else: ?>
                  <iframe src="<?= e(video_embed_url($video)) ?>" title="<?= e($video['title'] ?: 'Product video') ?>" allowfullscreen loading="lazy"></iframe>
                <?php endif; ?>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>

      <div class="col-lg-6">
        <div class="mb-2">
          <?php if ($product['category_name']): ?>
            <a class="product-cat d-inline-block" href="<?= e(Url::to('products.php?category=' . urlencode((string) $product['category_slug']))) ?>"><?= e($product['category_name']) ?></a>
          <?php endif; ?>
        </div>
        <h1 class="h2 mb-2"><?= e($product['name']) ?></h1>
        <?php if ($product['sku']): ?><p class="small text-muted mb-2">SKU: <?= e($product['sku']) ?></p><?php endif; ?>
        <p class="text-muted"><?= e($product['short_description']) ?></p>

        <div class="product-price mb-3">
          <span class="now" style="font-size:1.7rem"><?= money($product['price']) ?></span>
          <?php if ($discount > 0): ?>
            <span class="was"><?= money($product['mrp']) ?></span>
            <span class="off"><?= $discount ?>% OFF</span>
          <?php endif; ?>
          <span class="small text-muted ms-2">incl. of applicable taxes</span>
        </div>

        <div class="trust-strip mb-3">
          <span><i class="fa-solid fa-box"></i><?= $stock > 0 ? 'In stock (' . $stock . ' units)' : 'Currently out of stock' ?></span>
          <?php if ($product['packaging_sizes']): ?><span><i class="fa-solid fa-flask"></i><?= e($product['packaging_sizes']) ?></span><?php endif; ?>
          <span><i class="fa-solid fa-truck-fast"></i>Dispatch in 1-2 days</span>
        </div>

        <div class="d-flex gap-2 flex-wrap align-items-center mb-3">
          <div class="input-group qty-input">
            <button class="btn btn-outline-secondary" type="button" data-qty-step="down" aria-label="Decrease quantity"><i class="fa-solid fa-minus"></i></button>
            <input type="number" class="form-control text-center" value="<?= max(1, (int) $product['min_order_qty']) ?>" min="<?= max(1, (int) $product['min_order_qty']) ?>" data-qty-input aria-label="Quantity">
            <button class="btn btn-outline-secondary" type="button" data-qty-step="up" aria-label="Increase quantity"><i class="fa-solid fa-plus"></i></button>
          </div>
          <button class="btn btn-primary" data-add-to-cart="<?= (int) $product['id'] ?>" <?= $stock === 0 ? 'disabled' : '' ?>>
            <i class="fa-solid fa-cart-plus me-2"></i><?= $stock === 0 ? 'Out of stock' : 'Add to cart' ?>
          </button>
          <button class="btn btn-outline-primary <?= $inWishlist ? 'active' : '' ?>" data-wishlist="<?= (int) $product['id'] ?>">
            <i class="<?= $inWishlist ? 'fa-solid' : 'fa-regular' ?> fa-heart me-1"></i>Wishlist
          </button>
        </div>

        <?php if ($product['suitable_crops']): ?>
          <div class="panel p-3 mb-3">
            <div class="row g-2 small">
              <div class="col-sm-6"><strong>Suitable crops:</strong><br><?= e($product['suitable_crops']) ?></div>
              <?php if ($product['target_pests'] && $product['target_pests'] !== '-'): ?>
                <div class="col-sm-6"><strong>Target pests:</strong><br><?= e($product['target_pests']) ?></div>
              <?php endif; ?>
              <?php if ($product['dosage']): ?>
                <div class="col-sm-6"><strong>Dosage:</strong><br><?= e($product['dosage']) ?></div>
              <?php endif; ?>
              <?php if ($product['packaging_sizes']): ?>
                <div class="col-sm-6"><strong>Pack sizes:</strong><br><?= e($product['packaging_sizes']) ?></div>
              <?php endif; ?>
            </div>
          </div>
        <?php endif; ?>

        <?php $share = share_links(product_url((string) $product['slug']), $product['name']); ?>
        <div class="d-flex align-items-center gap-2 small text-muted">
          <span>Share:</span>
          <a class="btn btn-sm btn-light-green" href="<?= e($share['whatsapp']) ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="fab fa-whatsapp"></i></a>
          <a class="btn btn-sm btn-light-green" href="<?= e($share['facebook']) ?>" target="_blank" rel="noopener" aria-label="Share on Facebook"><i class="fab fa-facebook-f"></i></a>
          <a class="btn btn-sm btn-light-green" href="<?= e($share['x']) ?>" target="_blank" rel="noopener" aria-label="Share on X"><i class="fab fa-x-twitter"></i></a>
          <button class="btn btn-sm btn-light-green" data-copy="<?= e(product_url((string) $product['slug'])) ?>"><i class="fa-regular fa-copy"></i> Copy link</button>
        </div>
      </div>
    </div>

    <div class="mt-5">
      <ul class="nav nav-tabs" role="tablist">
        <?php
        $tabs = [
            'description' => 'Description',
            'benefits' => 'Benefits',
            'application' => 'Application & Dosage',
            'specs' => 'Specifications',
            'precautions' => 'Precautions',
        ];
        $first = true;
        foreach ($tabs as $key => $label):
            $content = match ($key) {
                'description' => $product['description'],
                'benefits' => $product['benefits'],
                'application' => trim(($product['application_instructions'] ?? '') . ($product['dosage'] ? '<p><strong>Dosage:</strong> ' . e($product['dosage']) . '</p>' : '')),
                'specs' => $product['technical_specifications'],
                'precautions' => $product['precautions'],
            };
            if (trim((string) $content) === '') { continue; }
            ?>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= $first ? 'active' : '' ?>" data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button" role="tab"><?= e($label) ?></button>
          </li>
        <?php $first = false; endforeach; ?>
      </ul>
      <div class="tab-content panel p-4" style="border-top-left-radius:0">
        <?php $first = true; foreach ($tabs as $key => $label):
            $content = match ($key) {
                'description' => $product['description'],
                'benefits' => $product['benefits'],
                'application' => trim(($product['application_instructions'] ?? '') . ($product['dosage'] ? '<p><strong>Dosage:</strong> ' . e($product['dosage']) . '</p>' : '')),
                'specs' => $product['technical_specifications'],
                'precautions' => $product['precautions'],
            };
            if (trim((string) $content) === '') { continue; } ?>
          <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="tab-<?= $key ?>" role="tabpanel">
            <div class="prose mb-0"><?= \Saee\Lib\Security::cleanHtml($content) ?></div>
          </div>
        <?php $first = false; endforeach; ?>
      </div>
    </div>

    <?php if ($related !== []): ?>
      <div class="mt-5">
        <h2 class="h4 mb-3">You may also like</h2>
        <div class="row g-4">
          <?php foreach ($related as $product): ?>
            <div class="col-6 col-lg-3"><?php include INCLUDES_PATH . '/product-card.php'; ?></div>
          <?php endforeach; ?>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
