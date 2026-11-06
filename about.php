<?php
/** About us page - dynamic CMS content plus values, timeline and leadership blocks. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Security;
use Saee\Lib\Url;

$page = get_page_by_slug('about-us');
$stats = site_stats();
$pageTitle = $page['meta_title'] ?? 'About Us';
$pageDescription = $page['meta_description'] ?? 'Learn about Saee Life Herbal - a farmer-first agricultural inputs company delivering herbal crop protection and nutrition across India.';
require INCLUDES_PATH . '/header.php';

echo page_hero('About Saee Life Herbal', 'Farmer first. Herbal always. Building healthier harvests across India.', ['About Us' => ''], $page['banner_image'] ?? null);
?>
<section class="section">
  <div class="container">
    <div class="row g-5 align-items-center">
      <div class="col-lg-6 reveal">
        <span class="eyebrow">Our story</span>
        <h2 class="section-title">From a small agri-input venture to a farmer network</h2>
        <div class="prose">
          <?= Security::cleanHtml($page['content'] ?? '') ?>
        </div>
        <div class="row g-3 mt-2">
          <div class="col-6"><div class="panel p-3 text-center"><div class="h4 mb-0 text-primary"><?= (int) $stats['members'] ?>+</div><div class="small text-muted">Partner farmers</div></div></div>
          <div class="col-6"><div class="panel p-3 text-center"><div class="h4 mb-0 text-primary"><?= (int) $stats['products'] ?>+</div><div class="small text-muted">Products</div></div></div>
        </div>
      </div>
      <div class="col-lg-6 reveal">
        <div class="ratio-cover shadow-saee">
          <img src="<?= e(Url::media($page['featured_image'] ?: 'assets/images/demo/hero-3.jpg')) ?>" alt="Saee Life Herbal farmers" class="w-100" style="aspect-ratio:4/3;object-fit:cover" loading="lazy">
        </div>
      </div>
    </div>
  </div>
</section>

<section class="section bg-white">
  <div class="container">
    <div class="text-center reveal">
      <span class="eyebrow">What we stand for</span>
      <h2 class="section-title">Our operating principles</h2>
    </div>
    <div class="row g-4">
      <?php foreach ([
          ['fa-leaf', 'Herbal-first science', 'We prioritise botanical and bio-based actives wherever they deliver dependable field results.'],
          ['fa-shield-halved', 'Compliance &amp; safety', 'Every product is registered where applicable, with clear dosage, PPE and storage guidance.'],
          ['fa-scale-balanced', 'Transparent economics', 'Commission structures are published, product wise and tied to genuine paid sales.'],
          ['fa-graduation-cap', 'Farmer education', 'Field demonstrations, knowledge articles and helpline support in local languages.'],
          ['fa-recycle', 'Soil stewardship', 'Programmes encourage organic carbon building and reduced chemical load.'],
          ['fa-people-arrows', 'Fair partnership', 'No joining fee, no recruitment-only income, and clear payout rules for every partner.'],
      ] as [$icon, $title, $text]): ?>
        <div class="col-md-6 col-lg-4 reveal">
          <div class="feature-card h-100">
            <div class="feature-icon"><i class="fa-solid <?= $icon ?>"></i></div>
            <h3><?= $title ?></h3>
            <p><?= $text ?></p>
          </div>
        </div>
      <?php endforeach; ?>
    </div>
  </div>
</section>

<section class="section">
  <div class="container">
    <div class="row g-5">
      <div class="col-lg-6 reveal">
        <span class="eyebrow">Journey</span>
        <h2 class="section-title mb-4">Milestones</h2>
        <ul class="timeline">
          <li><strong>Founded</strong><div class="text-muted small">Saee Life Herbal begins with a focused range of herbal crop protection products for cotton and soybean growers.</div><span class="t-time">Year one</span></li>
          <li><strong>Distribution network</strong><div class="text-muted small">District level distributors appointed across Vidarbha and Marathwada.</div><span class="t-time">Growth phase</span></li>
          <li><strong>Digital platform</strong><div class="text-muted small">E-commerce and the member portal launched so farmers can order and track earnings online.</div><span class="t-time">Digital</span></li>
          <li><strong>Unilevel partner programme</strong><div class="text-muted small">Transparent, product-sale based direct and grand-direct commissions introduced.</div><span class="t-time">Today</span></li>
        </ul>
      </div>
      <div class="col-lg-6 reveal">
        <span class="eyebrow">Reach</span>
        <h2 class="section-title mb-4">Where we work</h2>
        <div class="panel p-4">
          <ul class="list-check mb-0">
            <li>Maharashtra - Vidarbha, Marathwada and Western Maharashtra</li>
            <li>Madhya Pradesh and Chhattisgarh</li>
            <li>Gujarat and Rajasthan (select districts)</li>
            <li>Karnataka, Telangana and Andhra Pradesh</li>
          </ul>
          <hr>
          <p class="small text-muted mb-0">Crops served: cotton, soybean, paddy, sugarcane, tur, gram, onion, chilli, horticulture and vegetables.</p>
        </div>
        <div class="mt-4 d-flex gap-2 flex-wrap">
          <a href="<?= e(Url::to('our-products')) ?>" class="btn btn-primary">Explore products</a>
          <a href="<?= e(Url::to('contact-us')) ?>" class="btn btn-outline-primary">Talk to us</a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php
// Founder & full team — managed in Admin → Founder & Team.
$teamRole = null;
$teamHome = false;
$teamEyebrow = 'Our people';
$teamTitle = 'Founder and team';
$teamLead = 'Field agronomists, formulation chemists and farmer support staff working for Indian farms.';
require INCLUDES_PATH . '/team-section.php';
?>

<?php require INCLUDES_PATH . '/footer.php'; ?>
