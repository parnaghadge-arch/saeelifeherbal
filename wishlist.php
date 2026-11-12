<?php
/** Wishlist page. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Cart;
use Saee\Lib\Url;

$cart = new Cart(db());
$ids = $cart->wishlistIds();
$products = [];
if ($ids !== []) {
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $products = db()->select(
        "SELECT p.*, c.name AS category_name, c.slug AS category_slug FROM products p
         LEFT JOIN product_categories c ON c.id = p.category_id
         WHERE p.id IN ($placeholders) AND p.status = 'active'",
        $ids
    );
}
$pageTitle = 'My Wishlist';
$pageRobots = 'noindex,follow';
require INCLUDES_PATH . '/header.php';
echo page_hero('My Wishlist', count($products) . ' saved product(s)', ['Wishlist' => '']);
?>
<section class="section">
  <div class="container">
    <?php if ($products === []): ?>
      <div class="panel p-5 text-center"><i class="fa-regular fa-heart fs-1 text-muted mb-3"></i><h2 class="h5">Your wishlist is empty</h2><a class="btn btn-primary btn-sm" href="<?= e(Url::to('our-products')) ?>">Browse products</a></div>
    <?php else: ?>
      <div class="row g-4">
        <?php foreach ($products as $product): ?>
          <div class="col-6 col-lg-3"><?php include INCLUDES_PATH . '/product-card.php'; ?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
