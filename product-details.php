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
    . (new SeoService(db()))->breadcrumbSchema(['Home' => Url::absoluteBase() . '/', 'Products' => Url::to('our-products'), $product['name'] => product_url((string) $product['slug'])]);

require INCLUDES_PATH . '/header.php';
?>
<?php // A slim identity band instead of the tall marketing hero: the product name, its
      // category and the breadcrumb, so the spec sheet starts higher on the page. ?>
<div class="product-idband">
  <div class="container">
    <?php // breadcrumbs() prepends Home itself. ?>
    <?= breadcrumbs(['Products' => 'our-products', ($product['category_name'] ?? 'Category') => 'products.php?category=' . urlencode((string) ($product['category_slug'] ?? '')), $product['name'] => '']) ?>
    <div class="product-idband-row">
      <div class="min-w-0">
        <h1><?= e($product['name']) ?></h1>
        <p class="product-idband-meta">
          <?php if ($product['category_name']): ?><span><i class="fa-solid fa-layer-group"></i><?= e($product['category_name']) ?></span><?php endif; ?>
          <?php if (!empty($product['sku'])): ?><span><i class="fa-solid fa-barcode"></i>SKU <?= e((string) $product['sku']) ?></span><?php endif; ?>
          <span><i class="fa-solid <?= $stock > 0 ? 'fa-circle-check' : 'fa-circle-xmark' ?>"></i><?= $stock > 0 ? 'In stock (' . $stock . ' units)' : 'Out of stock' ?></span>
        </p>
      </div>
      <?php if ($discount > 0): ?><span class="product-idband-off"><?= $discount ?>% OFF</span><?php endif; ?>
    </div>
  </div>
</div>
<section class="section">
  <div class="container">
    <div class="row g-4 g-lg-5">
      <div class="col-lg-6">
        <div class="gallery-main" data-gallery-stage>
          <img data-gallery-main src="<?= e(Url::media($images[0]['image_path'] ?? '')) ?>"
               alt="<?= e($product['name']) ?>" fetchpriority="high">
          <?php if (count($images) > 1): ?>
            <button type="button" class="gallery-nav gallery-prev" data-gallery-prev aria-label="Previous image">
              <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
            </button>
            <button type="button" class="gallery-nav gallery-next" data-gallery-next aria-label="Next image">
              <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </button>
            <span class="gallery-counter" data-gallery-counter>1 / <?= count($images) ?></span>
          <?php endif; ?>
        </div>
        <?php if (count($images) > 1): ?>
          <?php // Thumbnail strip: auto-scrolls, has its own prev/next arrows, and supports
                // wheel/trackpad, mouse-drag and finger-swipe scrolling. ?>
          <div class="gallery-thumbs-wrap" data-gallery-wrap>
            <button type="button" class="thumb-nav thumb-prev" data-thumb-prev aria-label="Scroll thumbnails left">
              <i class="fa-solid fa-chevron-left" aria-hidden="true"></i>
            </button>
            <div class="gallery-thumbs" data-gallery-thumbs role="tablist" aria-label="Product images">
              <?php foreach ($images as $i => $img): ?>
                <button type="button" role="tab" class="<?= $i === 0 ? 'active' : '' ?>" aria-selected="<?= $i === 0 ? 'true' : 'false' ?>"
                        data-gallery-thumb="<?= e(Url::media($img['image_path'])) ?>"
                        data-gallery-index="<?= (int) $i ?>" aria-label="View image <?= $i + 1 ?>">
                  <img src="<?= e(Url::media($img['image_path'])) ?>" alt="<?= e($img['alt_text'] ?: $product['name']) ?>" loading="lazy">
                </button>
              <?php endforeach; ?>
            </div>
            <button type="button" class="thumb-nav thumb-next" data-thumb-next aria-label="Scroll thumbnails right">
              <i class="fa-solid fa-chevron-right" aria-hidden="true"></i>
            </button>
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
        <div class="product-buy">
          <p class="product-lede"><?= e($product['short_description']) ?></p>

          <div class="product-price">
            <span class="now"><?= money($product['price']) ?></span>
            <?php if ($discount > 0): ?>
              <span class="was"><?= money($product['mrp']) ?></span>
              <span class="off"><?= $discount ?>% OFF</span>
            <?php endif; ?>
            <span class="price-note">incl. of applicable taxes</span>
          </div>

        <div class="trust-strip">
          <span><i class="fa-solid fa-box"></i><?= $stock > 0 ? 'In stock (' . $stock . ' units)' : 'Currently out of stock' ?></span>
          <?php if ($product['packaging_sizes']): ?><span><i class="fa-solid fa-flask"></i><?= e($product['packaging_sizes']) ?></span><?php endif; ?>
          <span><i class="fa-solid fa-truck-fast"></i>Dispatch in 1-2 days</span>
        </div>

        <div class="buy-box">
          <div class="qty-input">
            <button class="qty-btn" type="button" data-qty-step="down" aria-label="Decrease quantity"><i class="fa-solid fa-minus" aria-hidden="true"></i></button>
            <input type="number" class="qty-field" value="<?= max(1, (int) $product['min_order_qty']) ?>" min="<?= max(1, (int) $product['min_order_qty']) ?>" step="1" inputmode="numeric" data-qty-input aria-label="Quantity">
            <button class="qty-btn" type="button" data-qty-step="up" aria-label="Increase quantity"><i class="fa-solid fa-plus" aria-hidden="true"></i></button>
          </div>
          <button class="btn btn-primary btn-cart" data-add-to-cart="<?= (int) $product['id'] ?>" <?= $stock === 0 ? 'disabled' : '' ?>>
            <i class="fa-solid fa-cart-plus" aria-hidden="true"></i><span data-btn-label><?= $stock === 0 ? 'Out of stock' : 'Add to cart' ?></span>
          </button>
          <button class="btn btn-wishlist <?= $inWishlist ? 'is-active' : '' ?>" data-wishlist="<?= (int) $product['id'] ?>" aria-pressed="<?= $inWishlist ? 'true' : 'false' ?>">
            <i class="<?= $inWishlist ? 'fa-solid' : 'fa-regular' ?> fa-heart" aria-hidden="true"></i>
            <span data-wishlist-label><?= $inWishlist ? 'Saved' : 'Wishlist' ?></span>
          </button>
        </div>

          <?php // Key facts as a definition list — the same shape as a spec sheet, so the
                // agronomy data reads as reference material rather than marketing copy. ?>
          <?php
          $facts = array_filter([
              'Suitable crops' => $product['suitable_crops'] ?? '',
              'Target pests' => ($product['target_pests'] && $product['target_pests'] !== '-') ? $product['target_pests'] : '',
              'Dosage' => $product['dosage'] ?? '',
              'Pack sizes' => $product['packaging_sizes'] ?? '',
              'Application' => $product['application_instructions'] ?? '',
          ], static fn ($v) => trim((string) $v) !== '');
          ?>
          <?php if ($facts !== []): ?>
            <div class="product-facts">
              <h2 class="product-facts-title">At a glance</h2>
              <dl>
                <?php foreach ($facts as $label => $value): ?>
                  <div class="product-fact">
                    <dt><?= e($label) ?></dt>
                    <dd><?= nl2br(e(strip_tags((string) $value))) ?></dd>
                  </div>
                <?php endforeach; ?>
              </dl>
            </div>
          <?php endif; ?>

          </div><!-- /.product-buy -->

          <?php $share = share_links(product_url((string) $product['slug']), $product['name']); ?>
          <div class="product-share">
            <span class="product-share-label">Share this product</span>
            <a class="btn btn-sm btn-light-green" href="<?= e($share['whatsapp']) ?>" target="_blank" rel="noopener" aria-label="Share on WhatsApp"><i class="fab fa-whatsapp"></i></a>
            <a class="btn btn-sm btn-light-green" href="<?= e($share['facebook']) ?>" target="_blank" rel="noopener" aria-label="Share on Facebook"><i class="fab fa-facebook-f"></i></a>
            <a class="btn btn-sm btn-light-green" href="<?= e($share['x']) ?>" target="_blank" rel="noopener" aria-label="Share on X"><i class="fab fa-x-twitter"></i></a>
            <button class="btn btn-sm btn-light-green" data-copy="<?= e(product_url((string) $product['slug'])) ?>"><i class="fa-regular fa-copy"></i><span>Copy link</span></button>
          </div>
      </div>
    </div>

    <?php
    // Build the tab list once, dropping the ones this product has no content for.
    $tabDefs = [
        'description' => ['Description', 'fa-solid fa-align-left'],
        'benefits' => ['Benefits', 'fa-solid fa-circle-check'],
        'application' => ['Application & Dosage', 'fa-solid fa-spray-can-sparkles'],
        'specs' => ['Specifications', 'fa-solid fa-list-check'],
        'precautions' => ['Precautions', 'fa-solid fa-triangle-exclamation'],
    ];
    $tabContent = [];
    foreach ($tabDefs as $key => $meta) {
        $content = match ($key) {
            'description' => $product['description'],
            'benefits' => $product['benefits'],
            'application' => trim(($product['application_instructions'] ?? '') . ($product['dosage'] ? '<p><strong>Dosage:</strong> ' . e($product['dosage']) . '</p>' : '')),
            'specs' => $product['technical_specifications'],
            'precautions' => $product['precautions'],
        };
        if (trim((string) $content) === '') {
            continue;
        }
        // The page already shows the product name in the identity band; a description that
        // opens with its own <h1>/<h2> repeating the name reads as a duplicate heading.
        $content = preg_replace(
            '#^\s*<h[12][^>]*>\s*' . preg_quote(trim((string) $product['name']), '#') . '\s*</h[12]>\s*#i',
            '',
            (string) $content
        );
        $tabContent[$key] = $content;
    }
    ?>
    <?php if ($tabContent !== []): ?>
    <?php // Sticky tab rail: the tab list (left on desktop, a scrollable pill row on phones)
          // stays pinned while only the active panel scrolls, so the user never loses the tabs. ?>
    <div class="product-tabs mt-5" data-product-tabs>
      <ul class="nav product-tab-rail" role="tablist" aria-orientation="vertical">
        <?php $first = true; foreach ($tabContent as $key => $content): ?>
          <li class="nav-item" role="presentation">
            <button class="nav-link <?= $first ? 'active' : '' ?>" id="tab-btn-<?= $key ?>"
                    data-bs-toggle="tab" data-bs-target="#tab-<?= $key ?>" type="button" role="tab"
                    aria-controls="tab-<?= $key ?>" aria-selected="<?= $first ? 'true' : 'false' ?>">
              <i class="<?= $tabDefs[$key][1] ?>" aria-hidden="true"></i>
              <span><?= e($tabDefs[$key][0]) ?></span>
            </button>
          </li>
        <?php $first = false; endforeach; ?>
      </ul>
      <div class="tab-content product-tab-panel">
        <?php $first = true; foreach ($tabContent as $key => $content): ?>
          <div class="tab-pane fade <?= $first ? 'show active' : '' ?>" id="tab-<?= $key ?>" role="tabpanel"
               aria-labelledby="tab-btn-<?= $key ?>" tabindex="0">
            <div class="prose mb-0"><?= \Saee\Lib\Security::cleanHtml($content) ?></div>
          </div>
        <?php $first = false; endforeach; ?>
      </div>
    </div>
    <?php endif; ?>

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
