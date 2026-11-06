<?php
/** Customer account dashboard with profile and address management. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\CustomerAuth;
use Saee\Lib\Request;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

CustomerAuth::requireLogin('login.php');
$customer = CustomerAuth::user();

if (is_post()) {
    Security::verifyCsrf();
    $action = (string) Request::input('action', 'profile');
    if ($action === 'profile') {
        $v = new Validator($_POST);
        $v->label('name', 'Full name')->required('name')->min('name', 3);
        $v->label('email', 'Email')->email('email');
        if ($v->passes()) {
            db()->update('customers', [
                'name' => $v->value('name'),
                'email' => $v->value('email') ?: null,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $customer['id']]);
            Session::success('Profile updated.');
            Request::redirect('my-account.php');
        }
        Session::error($v->firstError());
    } elseif ($action === 'password') {
        $current = (string) Request::raw('current_password', '');
        $v = new Validator($_POST);
        $v->label('password', 'New password')->required('password')->password('password');
        $v->label('password_confirm', 'Confirm password')->required('password_confirm')->matches('password_confirm', 'password');
        if (!Security::verifyPassword($current, (string) $customer['password_hash'])) {
            $v->addError('current_password', 'Your current password is incorrect.');
        }
        if ($v->passes()) {
            db()->update('customers', [
                'password_hash' => Security::passwordHash((string) Request::raw('password')),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $customer['id']]);
            Session::success('Password changed successfully.');
            Request::redirect('my-account.php');
        }
        Session::error($v->firstError());
    } elseif ($action === 'address') {
        $v = new Validator($_POST);
        $v->label('full_name', 'Full name')->required('full_name');
        $v->label('phone', 'Mobile')->required('phone')->phone('phone', true);
        $v->label('address_line1', 'Address')->required('address_line1');
        $v->label('city', 'City')->required('city');
        $v->label('state', 'State')->required('state');
        $v->label('pincode', 'PIN code')->required('pincode')->pincode('pincode', true);
        if ($v->passes()) {
            db()->update('customer_addresses', ['is_default' => 0], 'customer_id = :c', ['c' => $customer['id']]);
            db()->insert('customer_addresses', [
                'customer_id' => $customer['id'],
                'address_type' => 'shipping',
                'full_name' => $v->value('full_name'),
                'phone' => preg_replace('/\D/', '', (string) $v->value('phone')),
                'address_line1' => $v->value('address_line1'),
                'address_line2' => $v->value('address_line2'),
                'city' => $v->value('city'),
                'district' => $v->value('district'),
                'state' => $v->value('state'),
                'pincode' => $v->value('pincode'),
                'is_default' => 1,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            Session::success('Address saved.');
            Request::redirect('my-account.php');
        }
        Session::error($v->firstError());
    }
}

$addresses = db()->select('SELECT * FROM customer_addresses WHERE customer_id = :c ORDER BY is_default DESC, id DESC', ['c' => $customer['id']]);
$orders = db()->select('SELECT * FROM orders WHERE customer_id = :c ORDER BY id DESC LIMIT 5', ['c' => $customer['id']]);
$orderStats = db()->first(
    "SELECT COUNT(*) AS total, COALESCE(SUM(grand_total),0) AS spend FROM orders WHERE customer_id = :c AND status NOT IN ('cancelled','refunded')",
    ['c' => $customer['id']]
);
$pageTitle = 'My Account';
$pageRobots = 'noindex,nofollow';
require INCLUDES_PATH . '/header.php';
echo page_hero('My Account', 'Welcome back, ' . $customer['name'], ['My Account' => '']);
?>
<section class="section">
  <div class="container">
    <?= render_flash() ?>
    <div class="row g-4">
      <div class="col-lg-4">
        <div class="panel p-4 text-center mb-3">
          <div class="avatar-circle mx-auto mb-2" style="width:64px;height:64px;font-size:1.2rem"><?= e(initials((string) $customer['name'])) ?></div>
          <h2 class="h5 mb-0"><?= e($customer['name']) ?></h2>
          <p class="small text-muted mb-2"><?= e($customer['mobile']) ?><?= $customer['email'] ? ' · ' . e($customer['email']) : '' ?></p>
          <?php if ($customer['member_id']): ?>
            <span class="member-id-chip"><i class="fa-solid fa-id-badge"></i>Partner account linked</span>
          <?php else: ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= e(Url::member('register')) ?>">Become a partner</a>
          <?php endif; ?>
        </div>
        <div class="panel">
          <div class="list-group list-group-flush">
            <a class="list-group-item list-group-item-action" href="<?= e(Url::to('my-orders.php')) ?>"><i class="fa-solid fa-box me-2 text-primary"></i>My Orders</a>
            <a class="list-group-item list-group-item-action" href="<?= e(Url::to('wishlist.php')) ?>"><i class="fa-regular fa-heart me-2 text-primary"></i>Wishlist</a>
            <a class="list-group-item list-group-item-action" href="<?= e(Url::member('dashboard')) ?>"><i class="fa-solid fa-users me-2 text-primary"></i>Partner Dashboard</a>
            <a class="list-group-item list-group-item-action" href="<?= e(Url::to('faqs')) ?>"><i class="fa-solid fa-circle-question me-2 text-primary"></i>Help &amp; FAQs</a>
            <a class="list-group-item list-group-item-action text-danger" href="<?= e(Url::to('logout.php')) ?>"><i class="fa-solid fa-right-from-bracket me-2"></i>Sign out</a>
          </div>
        </div>
      </div>
      <div class="col-lg-8">
        <div class="row g-3 mb-3">
          <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Orders</div><div class="stat-value"><?= (int) ($orderStats['total'] ?? 0) ?></div></div></div>
          <div class="col-6 col-md-4"><div class="stat-card"><div class="stat-label">Total spent</div><div class="stat-value" style="font-size:1.15rem"><?= money((float) ($orderStats['spend'] ?? 0)) ?></div></div></div>
          <div class="col-12 col-md-4"><div class="stat-card"><div class="stat-label">Wishlist</div><div class="stat-value"><?= wishlist_count() ?></div></div></div>
        </div>

        <div class="panel mb-3">
          <div class="panel-head"><h2>Profile</h2></div>
          <div class="panel-body">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="profile">
              <div class="row g-3">
                <div class="col-md-6"><label class="form-label">Full name</label><input name="name" class="form-control" value="<?= e($customer['name']) ?>" required></div>
                <div class="col-md-6"><label class="form-label">Mobile</label><input class="form-control" value="<?= e($customer['mobile']) ?>" disabled></div>
                <div class="col-md-6"><label class="form-label">Email</label><input name="email" type="email" class="form-control" value="<?= e($customer['email']) ?>"></div>
                <div class="col-md-6 d-flex align-items-end"><button class="btn btn-primary w-100" type="submit">Save changes</button></div>
              </div>
            </form>
          </div>
        </div>

        <div class="panel mb-3">
          <div class="panel-head"><h2>Addresses</h2><button class="btn btn-sm btn-outline-primary" data-bs-toggle="collapse" data-bs-target="#newAddress">Add address</button></div>
          <div class="panel-body">
            <div class="collapse mb-3" id="newAddress">
              <form method="post" class="border rounded-3 p-3 bg-page">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="address">
                <div class="row g-2">
                  <div class="col-md-6"><input name="full_name" class="form-control form-control-sm" placeholder="Full name" required></div>
                  <div class="col-md-6"><input name="phone" class="form-control form-control-sm" placeholder="Mobile number" required></div>
                  <div class="col-12"><input name="address_line1" class="form-control form-control-sm" placeholder="Address" required></div>
                  <div class="col-md-4"><input name="city" class="form-control form-control-sm" placeholder="City" required></div>
                  <div class="col-md-4"><input name="state" class="form-control form-control-sm" placeholder="State" required></div>
                  <div class="col-md-4"><input name="pincode" class="form-control form-control-sm" placeholder="PIN code" required></div>
                  <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Save address</button></div>
                </div>
              </form>
            </div>
            <?php if ($addresses === []): ?>
              <p class="small text-muted mb-0">No saved addresses yet.</p>
            <?php else: ?>
              <div class="row g-2">
                <?php foreach ($addresses as $address): ?>
                  <div class="col-md-6">
                    <div class="border rounded-3 p-3 h-100 small">
                      <div class="fw-semibold"><?= e($address['full_name']) ?> <?= $address['is_default'] ? '<span class="badge bg-soft-primary text-primary ms-1">Default</span>' : '' ?></div>
                      <div class="text-muted"><?= e($address['address_line1']) ?><?= $address['address_line2'] ? ', ' . e($address['address_line2']) : '' ?><br><?= e($address['city']) ?>, <?= e($address['state']) ?> - <?= e($address['pincode']) ?><br><?= e($address['phone']) ?></div>
                    </div>
                  </div>
                <?php endforeach; ?>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <div class="panel mb-3">
          <div class="panel-head"><h2>Recent orders</h2><a class="small" href="<?= e(Url::to('my-orders.php')) ?>">View all</a></div>
          <div class="panel-body p-0">
            <div class="table-responsive">
              <table class="table table-saee table-responsive-cards mb-0">
                <thead><tr><th>Order</th><th>Date</th><th>Status</th><th class="text-end">Total</th></tr></thead>
                <tbody>
                  <?php foreach ($orders as $order): ?>
                    <tr>
                      <td data-label="Order"><a href="<?= e(Url::to('my-orders.php?order=' . (int) $order['id'])) ?>"><?= e($order['order_number']) ?></a></td>
                      <td data-label="Date"><?= format_date($order['created_at']) ?></td>
                      <td data-label="Status"><?= status_badge($order['status']) ?></td>
                      <td data-label="Total" class="text-end"><?= money($order['grand_total']) ?></td>
                    </tr>
                  <?php endforeach; ?>
                </tbody>
              </table>
            </div>
          </div>
        </div>

        <div class="panel">
          <div class="panel-head"><h2>Change password</h2></div>
          <div class="panel-body">
            <form method="post">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="password">
              <div class="row g-3">
                <div class="col-md-4"><label class="form-label">Current password</label><input type="password" name="current_password" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">New password</label><input type="password" name="password" class="form-control" required></div>
                <div class="col-md-4"><label class="form-label">Confirm</label><input type="password" name="password_confirm" class="form-control" required></div>
                <div class="col-12"><button class="btn btn-primary btn-sm" type="submit">Update password</button></div>
              </div>
            </form>
          </div>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
