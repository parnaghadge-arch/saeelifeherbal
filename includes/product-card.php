<?php
/**
 * Reusable product card. Expects $product array.
 */
declare(strict_types=1);

use Saee\Lib\Url;

$img = $product['featured_image'] ?? '';
$discount = product_discount($product);
$inWishlist = in_array((int) $product['id'], (new \Saee\Lib\Cart(db()))->wishlistIds(), true);
$productUrl = product_url((string) $product['slug']);
?>
<div class="product-card">
  <div class="product-thumb">
    <a href="<?= e($productUrl) ?>" aria-label="<?= e($product['name']) ?>">
      <img src="<?= e(Url::media($img)) ?>" alt="<?= e($product['name']) ?>" loading="lazy" width="400" height="400">
    </a>
    <div class="product-badges">
      <?php if ($discount > 0): ?><span class="badge-discount"><?= $discount ?>% OFF</span><?php endif; ?>
      <?php if ((int) ($product['is_featured'] ?? 0) === 1): ?><span class="badge-soft">Featured</span><?php endif; ?>
      <?php if ((int) $product['stock_quantity'] === 0): ?><span class="badge-soft text-danger">Out of stock</span><?php endif; ?>
    </div>
    <button class="product-wish <?= $inWishlist ? 'active' : '' ?>" data-wishlist="<?= (int) $product['id'] ?>" aria-label="Add to wishlist">
      <i class="<?= $inWishlist ? 'fa-solid' : 'fa-regular' ?> fa-heart"></i>
    </button>
  </div>
  <div class="product-body">
    <div class="product-cat"><?= e($product['category_name'] ?? 'Agriculture') ?></div>
    <h3 class="product-name"><a href="<?= e($productUrl) ?>"><?= e($product['name']) ?></a></h3>
    <p class="product-desc text-truncate-2"><?= e(truncate_text($product['short_description'], 80)) ?></p>
    <div class="product-price">
      <span class="now"><?= money($product['price']) ?></span>
      <?php if ((float) $product['mrp'] > (float) $product['price']): ?>
        <span class="was"><?= money($product['mrp']) ?></span>
      <?php endif; ?>
    </div>
    <div class="product-actions">
      <button class="btn btn-primary btn-sm" data-add-to-cart="<?= (int) $product['id'] ?>" <?= (int) $product['stock_quantity'] === 0 ? 'disabled' : '' ?>>
        <i class="fa-solid fa-cart-plus me-1"></i>Add
      </button>
      <a class="btn btn-outline-primary btn-sm" href="<?= e($productUrl) ?>">Details</a>
    </div>
  </div>
</div>
