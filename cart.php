<?php
/** Shopping cart. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Cart;
use Saee\Lib\Url;

$cartService = new Cart(db());
$totals = $cartService->totals();
$freeAbove = (float) setting('free_shipping_above', 0);
$pageTitle = 'Shopping Cart';
$pageRobots = 'noindex,follow';
require INCLUDES_PATH . '/header.php';
echo page_hero('Shopping Cart', $totals['item_count'] . ' item(s) in your cart', ['Cart' => '']);
?>
<section class="section">
  <div class="container">
    <?= render_flash() ?>
    <?php if ($totals['items'] === []): ?>
      <div class="panel p-5 text-center">
        <i class="fa-solid fa-basket-shopping fs-1 text-muted mb-3"></i>
        <h2 class="h4">Your cart is empty</h2>
        <p class="text-muted">Browse our crop care range and add products to get started.</p>
        <a class="btn btn-primary" href="<?= e(Url::to('our-products')) ?>">Shop products</a>
      </div>
    <?php else: ?>
      <div class="row g-4">
        <div class="col-lg-8">
          <div class="panel">
            <div class="panel-head"><h2>Items</h2><a class="small" href="<?= e(Url::to('our-products')) ?>">Continue shopping</a></div>
            <div class="panel-body">
              <?php foreach ($totals['items'] as $item): ?>
                <div class="cart-line">
                  <img src="<?= e(Url::media($item['featured_image'])) ?>" alt="<?= e($item['name']) ?>" loading="lazy">
                  <div class="flex-grow-1">
                    <div class="d-flex justify-content-between gap-2">
                      <div>
                        <a class="fw-semibold text-dark-green" href="<?= e(product_url((string) $item['slug'])) ?>"><?= e($item['name']) ?></a>
                        <?php if ($item['variant_name']): ?><div class="small text-muted"><?= e($item['variant_name']) ?></div><?php endif; ?>
                        <div class="small text-muted"><?= money($item['unit_price']) ?> each</div>
                      </div>
                      <div class="text-end">
                        <div class="fw-semibold"><?= money((float) $item['unit_price'] * (int) $item['quantity']) ?></div>
                      </div>
                    </div>
                    <div class="d-flex align-items-center gap-2 mt-2">
                      <input type="number" class="form-control form-control-sm" style="max-width:90px" min="1" value="<?= (int) $item['quantity'] ?>" data-cart-qty="<?= (int) $item['id'] ?>" aria-label="Quantity">
                      <button class="btn btn-sm btn-outline-secondary" data-cart-remove="<?= (int) $item['id'] ?>"><i class="fa-solid fa-trash-can me-1"></i>Remove</button>
                    </div>
                  </div>
                </div>
              <?php endforeach; ?>
            </div>
          </div>
        </div>
        <div class="col-lg-4">
          <div class="summary-card">
            <h2 class="h6 mb-3">Order summary</h2>
            <div class="summary-row"><span>Subtotal</span><span><?= money($totals['subtotal']) ?></span></div>
            <div class="summary-row"><span>Tax</span><span><?= money($totals['tax']) ?></span></div>
            <div class="summary-row"><span>Shipping</span><span><?= $totals['shipping'] > 0 ? money($totals['shipping']) : 'Free' ?></span></div>
            <div class="summary-row total"><span>Total</span><span><?= money($totals['grand_total']) ?></span></div>
            <?php if ($freeAbove > 0 && $totals['subtotal'] < $freeAbove): ?>
              <p class="small text-muted mt-2 mb-0"><i class="fa-solid fa-truck me-1"></i>Add <?= money($freeAbove - $totals['subtotal']) ?> more for free shipping.</p>
            <?php endif; ?>
            <a href="<?= e(Url::to('checkout')) ?>" class="btn btn-primary w-100 mt-3">Proceed to checkout <i class="fa-solid fa-arrow-right ms-1"></i></a>
            <div class="text-center small text-muted mt-3"><i class="fa-solid fa-lock me-1"></i>Secure checkout</div>
          </div>
        </div>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
