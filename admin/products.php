<?php
/** Product catalogue: list + full editor (specs, MLM config, images, SEO). */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';
admin_require('products.manage');

use Saee\Lib\Audit;
use Saee\Lib\MlmCommissionEngine;
use Saee\Lib\Pager;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Uploader;
use Saee\Lib\Url;

$db = db();
$id = Request::int('id');
$product = $id ? $db->first('SELECT * FROM products WHERE id = :id', ['id' => $id]) : null;
$errors = [];
$categories = $db->select('SELECT id, name, parent_id FROM product_categories ORDER BY sort_order ASC, name ASC');

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', 'save');

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

    if ($action === 'delete-image') {
        $imageId = Request::int('image_id');
        $image = $db->first('SELECT * FROM product_images WHERE id = :id', ['id' => $imageId]);
        if ($image) {
            Uploader::delete((string) $image['image_path']);
            $db->delete('product_images', 'id = :id', ['id' => $imageId]);
            Audit::log('delete', 'products', 'Removed product image', 'product_images', $imageId);
            Session::success('Image removed.');
        }
        Request::redirect('products.php?id=' . Request::int('product_id'));
    }

    $data = [
        'name' => trim((string) Request::input('name', '')),
        'slug' => trim((string) Request::input('slug', '')),
        'category_id' => Request::int('category_id') ?: null,
        'sku' => (string) Request::input('sku', ''),
        'short_description' => (string) Request::input('short_description', ''),
        'description' => Security::cleanHtml((string) Request::raw('description', '')),
        'technical_specifications' => (string) Request::input('technical_specifications', ''),
        'benefits' => (string) Request::input('benefits', ''),
        'application_instructions' => (string) Request::input('application_instructions', ''),
        'dosage' => (string) Request::input('dosage', ''),
        'suitable_crops' => (string) Request::input('suitable_crops', ''),
        'target_pests' => (string) Request::input('target_pests', ''),
        'precautions' => (string) Request::input('precautions', ''),
        'packaging_sizes' => (string) Request::input('packaging_sizes', ''),
        'mrp' => round((float) Request::input('mrp', 0), 2),
        'price' => round((float) Request::input('price', 0), 2),
        'tax_percent' => round((float) Request::input('tax_percent', 0), 2),
        'stock_quantity' => Request::int('stock_quantity'),
        'min_order_qty' => max(1, Request::int('min_order_qty', 1)),
        'unit' => (string) Request::input('unit', 'piece'),
        'is_featured' => Request::bool('is_featured') ? 1 : 0,
        // products.status is ENUM('active','inactive') — "draft" must never reach it.
        'status' => Request::input('status') === 'inactive' ? 'inactive' : 'active',
        'meta_title' => (string) Request::input('meta_title', ''),
        'meta_description' => (string) Request::input('meta_description', ''),
        'meta_keywords' => (string) Request::input('meta_keywords', ''),
        'sort_order' => Request::int('sort_order'),
        'mlm_enabled' => Request::bool('mlm_enabled') ? 1 : 0,
        'direct_commission_type' => Request::input('direct_commission_type') === 'fixed' ? 'fixed' : 'percent',
        'direct_commission_value' => round((float) Request::input('direct_commission_value', 0), 2),
        'grand_direct_commission_type' => Request::input('grand_direct_commission_type') === 'fixed' ? 'fixed' : 'percent',
        'grand_direct_commission_value' => round((float) Request::input('grand_direct_commission_value', 0), 2),
        'commission_basis' => normalize_commission_basis((string) Request::input('commission_basis', '')),
        'royalty_eligible' => Request::bool('royalty_eligible') ? 1 : 0,
        'updated_at' => date('Y-m-d H:i:s'),
    ];

    if ($data['name'] === '') { $errors['name'] = ['Product name is required.']; }
    if ($data['price'] < 0 || $data['mrp'] < 0) { $errors['price'] = ['Price cannot be negative.']; }
    if ($data['mrp'] > 0 && $data['price'] > $data['mrp']) { $errors['price'] = ['Selling price cannot exceed MRP.']; }
    if ($data['slug'] === '') { $data['slug'] = unique_slug('products', 'slug', $data['name'], $product['id'] ?? null); }

    foreach (['featured_image' => 'products'] as $field => $folder) {
        if (!empty($_FILES[$field]['name'])) {
            $up = Uploader::store($_FILES[$field], $folder);
            if ($up['ok']) { $data[$field] = $up['path']; } else { $errors[$field] = [$up['message']]; }
        }
    }

    if (empty($errors)) {
        try {
            if ($product) {
                $db->update('products', $data, 'id = :id', ['id' => $product['id']]);
                Audit::log('update', 'products', 'Updated product ' . $data['name'], 'products', (int) $product['id'], Audit::diff($product, $data));
                $productId = (int) $product['id'];
                Session::success('Product updated.');
            } else {
                $data['created_at'] = date('Y-m-d H:i:s');
                $productId = $db->insert('products', $data);
                Audit::log('create', 'products', 'Created product ' . $data['name'], 'products', $productId);
                Session::success('Product created.');
            }

            // The values saved above are the commission configuration: MlmCommissionEngine reads
            // products.* directly when it processes an order, so there is nothing to sync.

            // Gallery images
            if (!empty($_FILES['gallery']['name'][0])) {
                $count = count($_FILES['gallery']['name']);
                $hasPrimary = $db->exists('product_images', 'product_id = :p AND is_primary = 1', ['p' => $productId]);
                for ($i = 0; $i < min($count, 8); $i++) {
                    $file = [
                        'name' => $_FILES['gallery']['name'][$i],
                        'type' => $_FILES['gallery']['type'][$i],
                        'tmp_name' => $_FILES['gallery']['tmp_name'][$i],
                        'error' => $_FILES['gallery']['error'][$i],
                        'size' => $_FILES['gallery']['size'][$i],
                    ];
                    $up = Uploader::store($file, 'products');
                    if ($up['ok']) {
                        $db->insert('product_images', [
                            'product_id' => $productId,
                            'image_path' => $up['path'],
                            'alt_text' => $data['name'],
                            'sort_order' => $i,
                            'is_primary' => $hasPrimary ? 0 : 1,
                            'created_at' => date('Y-m-d H:i:s'),
                        ]);
                        $hasPrimary = true;
                    }
                }
            }
            Request::redirect('products.php?id=' . $productId);
        } catch (Throwable $e) {
            $errors['_general'] = ['Could not save: ' . $e->getMessage()];
        }
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
$productImages = $product ? $db->select('SELECT * FROM product_images WHERE product_id = :p ORDER BY is_primary DESC, sort_order ASC', ['p' => (int) $product['id']]) : [];
$productVideos = $product ? $db->select('SELECT * FROM product_videos WHERE product_id = :p ORDER BY id DESC', ['p' => (int) $product['id']]) : [];

$pageTitle = 'Products';
$pageHeading = 'Product Catalogue';
$pageSub = $total . ' products · per-product MLM commission configuration';
$activeModule = 'products';
$formOpen = $product !== null || !empty($errors) || Request::query('new') !== null;
$pageActions = '<a class="btn btn-sm btn-primary" href="products.php?new=1"><i class="fa-solid fa-plus me-1"></i>New product</a>';
require __DIR__ . '/includes/layout.php';
?>
<div class="crud-layout<?= $formOpen ? ' is-form-open' : '' ?>">
  <div class="crud-form-col<?= $formOpen ? '' : ' d-none' ?>" id="crudForm">
    <div class="panel p-3 p-md-4">
      <div class="d-flex justify-content-between align-items-center mb-3">
        <h2 class="h6 mb-0"><?= $product ? 'Edit product' : 'Add product' ?></h2>
        <div class="d-flex gap-2">
        <?php if ($product): ?>
          <form method="post" class="d-inline" data-confirm="Delete this product permanently? Past orders keep their prices.">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
            <button class="btn btn-sm btn-outline-danger"><i class="fa-solid fa-trash-can me-1"></i>Delete</button>
          </form>
        <?php endif; ?>
        <?php if ($product): ?><a class="btn btn-sm btn-outline-secondary" href="products.php">New product</a><?php endif; ?>
      </div>
      </div>
      <?php if (!empty($errors['_general'])): ?><div class="alert alert-danger small"><?= e($errors['_general'][0]) ?></div><?php endif; ?>
      <?php if (!empty($errors)): ?><div class="alert alert-danger small">Please correct the highlighted fields.</div><?php endif; ?>
      <form method="post" enctype="multipart/form-data">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="save">
        <?php if ($product): ?><input type="hidden" name="id" value="<?= (int) $product['id'] ?>"><?php endif; ?>

        <h3 class="h6 text-muted">Basics</h3>
        <div class="row g-2 mb-3">
          <div class="col-md-8"><label class="form-label">Product name <span class="required-mark">*</span></label><input name="name" class="form-control<?= invalid_class($errors, 'name') ?>" value="<?= e((string) ($product['name'] ?? '')) ?>" required><?= field_error($errors, 'name') ?></div>
          <div class="col-md-4"><label class="form-label">SKU</label><input name="sku" class="form-control" value="<?= e((string) ($product['sku'] ?? '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Category</label>
            <select name="category_id" class="form-select">
              <option value="0">Uncategorised</option>
              <?php foreach ($categories as $cat): ?>
                <option value="<?= (int) $cat['id'] ?>" <?= (int) ($product['category_id'] ?? 0) === (int) $cat['id'] ? 'selected' : '' ?>><?= e(($cat['parent_id'] ? '— ' : '') . (string) $cat['name']) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-6"><label class="form-label">Slug</label><input name="slug" class="form-control" value="<?= e((string) ($product['slug'] ?? '')) ?>" placeholder="auto"></div>
          <div class="col-12"><label class="form-label">Short description</label><textarea name="short_description" class="form-control" rows="2" maxlength="500"><?= e((string) ($product['short_description'] ?? '')) ?></textarea></div>
          <div class="col-12"><label class="form-label">Full description</label><textarea name="description" class="form-control richtext" rows="6"><?= e((string) ($product['description'] ?? '')) ?></textarea></div>
        </div>

        <h3 class="h6 text-muted">Pricing &amp; stock</h3>
        <div class="row g-2 mb-3">
          <div class="col-6 col-md-3"><label class="form-label">MRP</label><input type="number" step="0.01" name="mrp" class="form-control" value="<?= e((string) ($product['mrp'] ?? 0)) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label">Price <span class="required-mark">*</span></label><input type="number" step="0.01" name="price" class="form-control<?= invalid_class($errors, 'price') ?>" value="<?= e((string) ($product['price'] ?? 0)) ?>"><?= field_error($errors, 'price') ?></div>
          <div class="col-6 col-md-3"><label class="form-label">Tax %</label><input type="number" step="0.01" name="tax_percent" class="form-control" value="<?= e((string) ($product['tax_percent'] ?? 0)) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label">Stock qty</label><input type="number" name="stock_quantity" class="form-control" value="<?= (int) ($product['stock_quantity'] ?? 0) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label">Min order qty</label><input type="number" name="min_order_qty" class="form-control" value="<?= (int) ($product['min_order_qty'] ?? 1) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label">Unit</label><input name="unit" class="form-control" value="<?= e((string) ($product['unit'] ?? 'piece')) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label">Status</label>
            <select name="status" class="form-select">
              <?php foreach (['active' => 'Active', 'inactive' => 'Inactive'] as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= ($product['status'] ?? 'active') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label">Sort order</label><input type="number" name="sort_order" class="form-control" value="<?= (int) ($product['sort_order'] ?? 0) ?>"></div>
          <div class="col-12">
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="is_featured" value="1" id="feat" <?= (int) ($product['is_featured'] ?? 0) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="feat">Featured on home</label></div>
            <div class="form-check form-check-inline"><input class="form-check-input" type="checkbox" name="royalty_eligible" value="1" id="roy" <?= (int) ($product['royalty_eligible'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="roy">Royalty eligible</label></div>
          </div>
        </div>

        <h3 class="h6 text-muted">Agronomy details</h3>
        <div class="row g-2 mb-3">
          <div class="col-md-6"><label class="form-label">Technical specifications</label><textarea name="technical_specifications" class="form-control" rows="2"><?= e((string) ($product['technical_specifications'] ?? '')) ?></textarea></div>
          <div class="col-md-6"><label class="form-label">Benefits</label><textarea name="benefits" class="form-control" rows="2"><?= e((string) ($product['benefits'] ?? '')) ?></textarea></div>
          <div class="col-md-6"><label class="form-label">Application instructions</label><textarea name="application_instructions" class="form-control" rows="2"><?= e((string) ($product['application_instructions'] ?? '')) ?></textarea></div>
          <div class="col-md-6"><label class="form-label">Dosage</label><textarea name="dosage" class="form-control" rows="2"><?= e((string) ($product['dosage'] ?? '')) ?></textarea></div>
          <div class="col-md-4"><label class="form-label">Suitable crops</label><input name="suitable_crops" class="form-control" value="<?= e((string) ($product['suitable_crops'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Target pests</label><input name="target_pests" class="form-control" value="<?= e((string) ($product['target_pests'] ?? '')) ?>"></div>
          <div class="col-md-4"><label class="form-label">Pack sizes</label><input name="packaging_sizes" class="form-control" value="<?= e((string) ($product['packaging_sizes'] ?? '')) ?>"></div>
          <div class="col-12"><label class="form-label">Precautions</label><textarea name="precautions" class="form-control" rows="2"><?= e((string) ($product['precautions'] ?? '')) ?></textarea></div>
        </div>

        <h3 class="h6 text-muted">MLM commission (this product)</h3>
        <div class="row g-2 mb-3">
          <div class="col-12"><div class="form-check"><input class="form-check-input" type="checkbox" name="mlm_enabled" value="1" id="mlm" <?= (int) ($product['mlm_enabled'] ?? 1) === 1 ? 'checked' : '' ?>><label class="form-check-label small" for="mlm">Enable commission on this product</label></div></div>
          <div class="col-6 col-md-3"><label class="form-label small">Direct type</label>
            <select name="direct_commission_type" class="form-select form-select-sm">
              <option value="percent" <?= ($product['direct_commission_type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option>
              <option value="fixed" <?= ($product['direct_commission_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed</option>
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label small">Direct value</label><input type="number" step="0.01" name="direct_commission_value" class="form-control form-control-sm" value="<?= e((string) ($product['direct_commission_value'] ?? 0)) ?>"></div>
          <div class="col-6 col-md-3"><label class="form-label small">Grand direct type</label>
            <select name="grand_direct_commission_type" class="form-select form-select-sm">
              <option value="percent" <?= ($product['grand_direct_commission_type'] ?? '') === 'percent' ? 'selected' : '' ?>>Percent</option>
              <option value="fixed" <?= ($product['grand_direct_commission_type'] ?? '') === 'fixed' ? 'selected' : '' ?>>Fixed</option>
            </select>
          </div>
          <div class="col-6 col-md-3"><label class="form-label small">Grand direct value</label><input type="number" step="0.01" name="grand_direct_commission_value" class="form-control form-control-sm" value="<?= e((string) ($product['grand_direct_commission_value'] ?? 0)) ?>"></div>
          <div class="col-12"><label class="form-label small">Commission basis</label>
            <select name="commission_basis" class="form-select form-select-sm">
              <?php foreach (commission_basis_options() as $k => $label): ?>
                <option value="<?= e($k) ?>" <?= normalize_commission_basis($product['commission_basis'] ?? '') === $k ? 'selected' : '' ?>><?= e($label) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>

        <h3 class="h6 text-muted">SEO &amp; media</h3>
        <div class="row g-2 mb-3">
          <div class="col-md-4"><label class="form-label">Meta title</label><input name="meta_title" class="form-control" maxlength="191" value="<?= e((string) ($product['meta_title'] ?? '')) ?>"></div>
          <div class="col-md-8"><label class="form-label">Meta description</label><input name="meta_description" class="form-control" maxlength="255" value="<?= e((string) ($product['meta_description'] ?? '')) ?>"></div>
          <div class="col-12"><label class="form-label">Meta keywords</label><input name="meta_keywords" class="form-control" value="<?= e((string) ($product['meta_keywords'] ?? '')) ?>"></div>
          <div class="col-md-6"><label class="form-label">Featured image</label>
            <?php if (!empty($product['featured_image'])): ?><div class="mb-1"><img class="crud-thumb" src="<?= e(Url::media((string) $product['featured_image'])) ?>" alt=""></div><?php endif; ?>
            <input type="file" name="featured_image" class="form-control" accept="image/*">
          </div>
          <div class="col-md-6"><label class="form-label">Gallery images</label><input type="file" name="gallery[]" class="form-control" accept="image/*" multiple><div class="form-text">Up to 8 images.</div></div>
        </div>
        <button class="btn btn-primary w-100"><?= $product ? 'Update product' : 'Create product' ?></button>
      </form>

      <?php if ($product): ?>
        <div class="d-flex gap-2 mt-2">
          <a class="btn btn-sm btn-outline-secondary flex-grow-1" target="_blank" rel="noopener" href="<?= e(product_url((string) $product['slug'])) ?>">View on site</a>
          <form method="post" class="flex-grow-1" data-confirm="<?= (string) $product['status'] === 'active' ? 'Deactivate' : 'Activate' ?> this product?">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="<?= (string) $product['status'] === 'active' ? 'deactivate' : 'activate' ?>">
            <input type="hidden" name="id" value="<?= (int) $product['id'] ?>">
            <button class="btn btn-sm <?= (string) $product['status'] === 'active' ? 'btn-outline-danger' : 'btn-outline-success' ?> w-100"><?= (string) $product['status'] === 'active' ? 'Deactivate' : 'Activate' ?></button>
          </form>
        </div>

        <hr>
        <h3 class="h6">Gallery (<?= count($productImages) ?>)</h3>
        <div class="d-flex flex-wrap gap-2">
          <?php foreach ($productImages as $image): ?>
            <form method="post" class="text-center" data-confirm="Remove this image?">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete-image">
              <input type="hidden" name="product_id" value="<?= (int) $product['id'] ?>">
              <input type="hidden" name="image_id" value="<?= (int) $image['id'] ?>">
              <img class="crud-thumb" src="<?= e(Url::media((string) $image['image_path'])) ?>" alt="">
              <button class="btn btn-sm btn-link text-danger d-block w-100">Remove</button>
            </form>
          <?php endforeach; ?>
          <?php if (!$productImages): ?><p class="small text-muted mb-0">No gallery images yet.</p><?php endif; ?>
        </div>
        <p class="small text-muted mt-3 mb-0">Product videos are managed in the <a href="product-media.php?id=<?= (int) $product['id'] ?>">product media</a> screen.</p>
      <?php endif; ?>
    </div>
  </div>

  <div class="crud-list-col">
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
                  <a class="btn btn-sm btn-light-green" href="?id=<?= (int) $row['id'] ?>" title="Edit"><i class="fa-solid fa-pen"></i></a>
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
  </div>
</div>
<?php
$extraScripts = richtext_scripts('textarea.richtext', 380);
require __DIR__ . '/includes/layout-end.php';
