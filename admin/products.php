<?php
/**
 * Product catalogue - the list.
 *
 * Adding and editing moved to product-form.php so the table is never redrawn underneath you
 * when you open an editor.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('products.manage');

use Saee\Lib\Audit;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

$db = db();

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', '');

    if ($action === 'delete') {
        $target = $db->first('SELECT * FROM products WHERE id = :id', ['id' => Request::int('id')]);
        if ($target) {
            try {
                // Detach every reference that is not covered by ON DELETE CASCADE/SET NULL so the
                // delete cannot fail with a foreign-key error on MySQL, then remove the product.
                // Past orders keep their own copy of name/price (order_items), so history and
                // invoices stay intact even after the catalogue row is gone.
                $db->execute('UPDATE order_items SET product_id = NULL WHERE product_id = :id', ['id' => (int) $target['id']]);
                $db->execute('DELETE FROM cart_items WHERE product_id = :id', ['id' => (int) $target['id']]);
                $db->execute('DELETE FROM wishlists WHERE product_id = :id', ['id' => (int) $target['id']]);
                $db->execute('UPDATE commission_transactions SET product_id = NULL WHERE product_id = :id', ['id' => (int) $target['id']]);
                $db->execute('DELETE FROM product_images WHERE product_id = :id', ['id' => (int) $target['id']]);
                $db->execute('DELETE FROM product_videos WHERE product_id = :id', ['id' => (int) $target['id']]);
                $db->execute('DELETE FROM product_variants WHERE product_id = :id', ['id' => (int) $target['id']]);
                $db->delete('products', 'id = :id', ['id' => (int) $target['id']]);
                if (!empty($target['featured_image'])) {
                    Uploader::delete((string) $target['featured_image']);
                }
                Audit::log('delete', 'products', 'Deleted product ' . $target['name'], 'products', (int) $target['id']);
                Session::success('Product deleted. Past orders keep their own copy of the price.');
            } catch (Throwable $e) {
                Session::error('Could not delete the product: ' . e($e->getMessage()));
            }
        }
        Request::redirect('products.php');
    }

    if ($action === 'deactivate') {
        $target = $db->first('SELECT * FROM products WHERE id = :id', ['id' => Request::int('id')]);
        if ($target) {
            $db->update('products', ['status' => 'inactive', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $target['id']]);
            Audit::log('deactivate', 'products', 'Deactivated product ' . $target['name'], 'products', (int) $target['id']);
            Session::success('Product deactivated (still listed here, hidden from the shop).');
        }
        Request::redirect('products.php');
    }

    if ($action === 'activate') {
        $target = $db->first('SELECT * FROM products WHERE id = :id', ['id' => Request::int('id')]);
        if ($target) {
            $db->update('products', ['status' => 'active', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => (int) $target['id']]);
            Audit::log('activate', 'products', 'Re-activated product ' . $target['name'], 'products', (int) $target['id']);
            Session::success('Product activated (visible in the shop again).');
        }
        Request::redirect('products.php');
    }
}

$search = trim((string) Request::query('q', ''));
$where = '1=1';
$params = [];
if ($search !== '') {
    $where = '(p.name LIKE :q OR p.sku LIKE :q)';
    $params['q'] = '%' . $search . '%';
}
$total = (int) $db->value("SELECT COUNT(*) FROM products p WHERE $where", $params);
$pager = new Pager($total, 20);
$rows = $db->select(
    "SELECT p.*, c.name AS category_name FROM products p LEFT JOIN product_categories c ON c.id = p.category_id
     WHERE $where ORDER BY p.sort_order ASC, p.id DESC" . $pager->limitSql(),
    $params
);


$pageTitle = 'Products';
$pageHeading = 'Product Catalogue';
$pageSub = $total . ' products · per-product MLM commission configuration';
$activeModule = 'products';
$pageActions = '<a class="btn btn-sm btn-primary" href="product-form.php"><i class="fa-solid fa-plus me-1"></i>New product</a>';
require __DIR__ . '/includes/layout.php';
?>
    <div class="panel p-3 p-md-4">
      <div class="crud-toolbar">
        <form class="d-flex gap-2 flex-grow-1" method="get">
          <input class="form-control form-control-sm" name="q" value="<?= e($search) ?>" placeholder="Search products / SKU">
          <button class="btn btn-sm btn-dark flex-shrink-0">Search</button>
        </form>
      </div>
      <div class="table-responsive">
        <table class="table table-hover align-middle mb-0 data-table">
          <thead><tr><th>Product</th><th class="text-end">Price</th><th>Status</th><th class="text-end">Edit</th></tr></thead>
          <tbody>
          <?php foreach ($rows as $row): ?>
            <tr>
              <td data-label="Product">
                <div class="d-flex align-items-center gap-2">
                  <?php if ($row['featured_image']): ?><img class="crud-thumb-sm" src="<?= e(Url::media((string) $row['featured_image'])) ?>" alt=""><?php endif; ?>
                  <div class="min-w-0">
                    <div class="small fw-semibold text-truncate"><?= e((string) $row['name']) ?></div>
                    <div class="small text-muted"><?= e((string) ($row['category_name'] ?? 'Uncategorised')) ?> · <?= (int) $row['stock_quantity'] ?> in stock</div>
                  </div>
                </div>
              </td>
              <td data-label="Price" class="text-end small"><?= money((float) $row['price']) ?><?php if ((float) $row['mrp'] > (float) $row['price']): ?><div class="text-muted text-decoration-line-through"><?= money((float) $row['mrp']) ?></div><?php endif; ?></td>
              <td data-label="Status"><?= status_badge((string) $row['status']) ?></td>
              <td class="text-end">
                <div class="table-tools justify-content-end">
                  <a class="btn btn-sm btn-light-green" href="product-form.php?id=<?= (int) $row['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
                  <?php if ((string) $row['status'] === 'active'): ?>
                    <form method="post" class="d-inline" data-confirm="Hide this product from the shop?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="deactivate">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <button class="btn btn-sm btn-outline-secondary" title="Deactivate"><i class="fa-solid fa-eye-slash"></i></button>
                    </form>
                  <?php else: ?>
                    <form method="post" class="d-inline" data-confirm="Show this product in the shop again?">
                      <?= csrf_field() ?>
                      <input type="hidden" name="action" value="activate">
                      <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                      <button class="btn btn-sm btn-outline-success" title="Activate"><i class="fa-solid fa-eye"></i></button>
                    </form>
                  <?php endif; ?>
                  <form method="post" class="d-inline" data-confirm="Delete this product permanently?">
                    <?= csrf_field() ?>
                    <input type="hidden" name="action" value="delete">
                    <input type="hidden" name="id" value="<?= (int) $row['id'] ?>">
                    <button class="btn btn-sm btn-outline-danger" title="Delete"><i class="fa-solid fa-trash-can"></i></button>
                  </form>
                </div>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php // DataTables renders its own "showing x of y" + pager, so the server one is only
            // kept for the no-JS fallback (hidden as soon as the table is enhanced). ?>
      <div class="d-flex justify-content-between align-items-center mt-3 server-pager">
        <div class="small text-muted"><?= $pager->infoText() ?></div>
        <?= $pager->links(['q' => $search]) ?>
      </div>
    </div>
<?php require __DIR__ . '/includes/layout-end.php'; ?>
