<?php
/** MLM business opportunity landing page (CMS driven + live commission examples). */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Security;
use Saee\Lib\Url;

$page = get_page_by_slug('mlm-business-opportunity');
$royalties = db()->select('SELECT * FROM royalty_rules WHERE status = 1 ORDER BY level_no ASC');
$sampleProducts = db()->select("SELECT name, price, direct_commission_type, direct_commission_value, grand_direct_commission_type, grand_direct_commission_value FROM products WHERE mlm_enabled = 1 AND status = 'active' ORDER BY sort_order LIMIT 4");
$pageTitle = 'Business Opportunity';
$pageDescription = 'Join the Saee Life Herbal free partner programme - earn product-wise direct and grand-direct commissions on genuine sales.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Grow with Saee Life Herbal', 'A free, product-sale based unilevel partner programme.', ['Business Opportunity' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-7">
        <div class="prose"><?= Security::cleanHtml($page['content'] ?? '') ?></div>
      </div>
      <div class="col-lg-5">
        <div class="panel p-4 mb-4">
          <h3 class="h5 mb-3">Register free</h3>
          <ul class="list-check small mb-3">
            <li>No joining fee, no mandatory kit</li>
            <li>Unique member ID, referral link &amp; QR code</li>
            <li>Unlimited direct referrals</li>
            <li>Live wallet and commission ledger</li>
          </ul>
          <a class="btn btn-primary w-100 mb-2" href="<?= e(Url::member('register')) ?>">Create partner account</a>
          <a class="btn btn-outline-primary w-100" href="<?= e(Url::member('login')) ?>">I already have an account</a>
        </div>
        <div class="panel p-4">
          <h3 class="h6 mb-2"><i class="fa-solid fa-user-tie text-primary me-2"></i>Have a sponsor ID?</h3>
          <p class="small text-muted mb-0">Enter your sponsor's member ID during registration. If you do not have one, your account is placed under the company sponsor automatically.</p>
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section bg-white">
  <div class="container">
    <div class="text-center reveal"><span class="eyebrow">Commission structure</span><h2 class="section-title">Direct &amp; grand-direct commissions</h2><p class="section-subtitle">Every product has its own commission configuration set by the company. Commission is calculated on the product selling price and paid only on paid, completed orders.</p></div>
    <div class="table-responsive">
      <table class="table table-saee align-middle">
        <thead><tr><th>Example product</th><th class="text-end">Selling price</th><th class="text-end">Direct sponsor</th><th class="text-end">Grand-direct sponsor</th></tr></thead>
        <tbody>
          <?php foreach ($sampleProducts as $p):
              $direct = $p['direct_commission_type'] === 'percent'
                  ? rtrim(rtrim(number_format((float) $p['price'] * (float) $p['direct_commission_value'] / 100, 2, '.', ''), '0'), '.')
                  : (float) $p['direct_commission_value'];
              $grand = $p['grand_direct_commission_type'] === 'percent'
                  ? rtrim(rtrim(number_format((float) $p['price'] * (float) $p['grand_direct_commission_value'] / 100, 2, '.', ''), '0'), '.')
                  : (float) $p['grand_direct_commission_value'];
              ?>
            <tr>
              <td><strong><?= e($p['name']) ?></strong></td>
              <td class="text-end"><?= money($p['price']) ?></td>
              <td class="text-end text-success fw-semibold"><?= money((float) $direct) ?><?= $p['direct_commission_type'] === 'percent' ? ' <span class="text-muted small">(' . (float) $p['direct_commission_value'] . '%)</span>' : '' ?></td>
              <td class="text-end"><?= money((float) $grand) ?><?= $p['grand_direct_commission_type'] === 'percent' ? ' <span class="text-muted small">(' . (float) $p['grand_direct_commission_value'] . '%)</span>' : '' ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <p class="small text-muted mb-0"><i class="fa-solid fa-circle-info me-1"></i>Illustrative values based on the current configuration. The company may revise commission rates prospectively with notice.</p>
  </div>
</section>

<?php if ($royalties !== []): ?>
<section class="section">
  <div class="container">
    <div class="text-center reveal"><span class="eyebrow">Recognition</span><h2 class="section-title">Royalty &amp; performance milestones</h2><p class="section-subtitle">Achievement slabs are configurable by the company and are based on verified team size and qualifying sales.</p></div>
    <div class="table-responsive">
      <table class="table table-saee align-middle text-center">
        <thead><tr><th>Level</th><th>Programme</th><th>Required team members</th><th>Reward</th></tr></thead>
        <tbody>
          <?php foreach ($royalties as $rule): ?>
            <tr>
              <td><span class="badge bg-soft-primary text-primary">Level <?= (int) $rule['level_no'] ?></span></td>
              <td class="text-start"><?= e($rule['name']) ?></td>
              <td><?= (int) $rule['required_downline'] ?></td>
              <td class="fw-semibold text-success">
                <?= $rule['reward_type'] === 'percent' ? (float) $rule['reward_percent'] . '%' : money((float) $rule['reward_amount']) ?>
                <span class="text-muted small"><?= $rule['frequency'] === 'recurring' ? 'monthly' : 'one-time' ?></span>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</section>
<?php endif; ?>

<section class="section bg-white">
  <div class="container">
    <div class="alert alert-warning small">
      <h3 class="h6 mb-2"><i class="fa-solid fa-scale-balanced me-2"></i>Important compliance disclosures</h3>
      <?= nl2br(e((string) setting('mlm_compliance_notice'))) ?>
      <ul class="mb-0 mt-2">
        <li>No commission is paid for registering members or for recruitment alone.</li>
        <li>There is no joining fee, no deposit requirement and no assured return.</li>
        <li>Commission is generated only from genuine paid product sales.</li>
        <li>If an order is cancelled or refunded, the related commissions are reversed.</li>
        <li>Please read our <a href="<?= e(Url::to('terms-conditions')) ?>" class="alert-link">Terms &amp; Conditions</a> and <a href="<?= e(Url::to('disclaimer')) ?>" class="alert-link">Disclaimer</a> before joining.</li>
      </ul>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
