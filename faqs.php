<?php
/** FAQ page grouped by category. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\Url;

$faqs = get_faqs();
$grouped = [];
foreach ($faqs as $faq) {
    $grouped[$faq['category']][] = $faq;
}
$pageTitle = 'Frequently Asked Questions';
$pageDescription = 'Answers about Saee Life Herbal products, orders, shipping, partner registration and payouts.';
require INCLUDES_PATH . '/header.php';
echo page_hero('Frequently Asked Questions', 'Everything about products, orders, the partner programme and payouts.', ['FAQs' => '']);
?>
<section class="section">
  <div class="container">
    <div class="row justify-content-center">
      <div class="col-lg-9">
        <?php foreach ($grouped as $cat => $items): ?>
          <h2 class="h5 mt-4 mb-3"><i class="fa-solid fa-circle-question text-primary me-2"></i><?= e($cat) ?></h2>
          <div class="accordion faq-accordion" id="faq-<?= e(slugify((string) $cat)) ?>">
            <?php foreach ($items as $i => $faq): ?>
              <div class="accordion-item">
                <h3 class="accordion-header">
                  <button class="accordion-button collapsed" type="button" data-bs-toggle="collapse" data-bs-target="#faq<?= (int) $faq['id'] ?>">
                    <?= e($faq['question']) ?>
                  </button>
                </h3>
                <div id="faq<?= (int) $faq['id'] ?>" class="accordion-collapse collapse" data-bs-parent="#faq-<?= e(slugify((string) $cat)) ?>">
                  <div class="accordion-body"><?= nl2br(e($faq['answer'])) ?></div>
                </div>
              </div>
            <?php endforeach; ?>
          </div>
        <?php endforeach; ?>
        <?php if ($grouped === []): ?><p class="text-muted">No FAQs published yet.</p><?php endif; ?>
        <div class="panel p-4 mt-5 text-center">
          <h3 class="h5">Still have a question?</h3>
          <p class="text-muted small">Our support team replies within one working day.</p>
          <a class="btn btn-primary" href="<?= e(Url::to('contact-us')) ?>">Contact support</a>
        </div>
      </div>
    </div>
  </div>
</section>
<?php require INCLUDES_PATH . '/footer.php'; ?>
