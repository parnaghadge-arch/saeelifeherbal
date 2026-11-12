<?php
/**
 * Founder & team section for the public site.
 *
 * Rendered on the home page and on About Us from the `team_members` table
 * (Admin → Founder & Team). Nothing here is hardcoded: if no visible profile exists the
 * section is skipped entirely, so the page never shows an empty block.
 *
 * Expects (all optional): $teamRole ('founder'|'team'|'advisor'|null), $teamHome (bool),
 * $teamEyebrow, $teamTitle, $teamLead, $teamLayout ('grid'|'strip').
 */
declare(strict_types=1);

use Saee\Lib\Url;

$teamRole = $teamRole ?? null;
$teamHome = $teamHome ?? false;
$teamEyebrow = $teamEyebrow ?? 'Leadership';
$teamTitle = $teamTitle ?? 'Meet the founder and our team';
$teamLead = $teamLead ?? 'The people who formulate, test and stand behind every Saee Life Herbal product.';
$teamLayout = $teamLayout ?? 'grid';

$teamMembers = get_team_members($teamRole, $teamHome ? 1 : null);
if (!$teamMembers) {
    return;
}

$founders = array_values(array_filter($teamMembers, static fn ($m) => ($m['role_type'] ?? '') === 'founder'));
$founder = $founders[0] ?? null;
$others = array_values(array_filter($teamMembers, static fn ($m) => $founder === null || (int) $m['id'] !== (int) $founder['id']));

$socials = static function (array $member): string {
    $links = [
        'linkedin_url' => 'fa-brands fa-linkedin-in',
        'facebook_url' => 'fa-brands fa-facebook-f',
        'instagram_url' => 'fa-brands fa-instagram',
        'x_url' => 'fa-brands fa-x-twitter',
    ];
    $html = '';
    foreach ($links as $key => $icon) {
        if (!empty($member[$key])) {
            $html .= '<a href="' . e((string) $member[$key]) . '" target="_blank" rel="noopener" aria-label="Profile"><i class="' . $icon . '"></i></a>';
        }
    }
    if (!empty($member['email'])) {
        $html .= '<a href="mailto:' . e((string) $member['email']) . '" aria-label="Email"><i class="fa-regular fa-envelope"></i></a>';
    }
    return $html !== '' ? '<div class="team-social">' . $html . '</div>' : '';
};

$photo = static function (array $member, string $class): string {
    if (!empty($member['photo'])) {
        return '<img class="' . $class . '" src="' . e(Url::media((string) $member['photo'])) . '" alt="' . e((string) $member['name']) . '" loading="lazy">';
    }
    return '<span class="' . $class . ' team-initials" aria-hidden="true">' . e(initials((string) $member['name'])) . '</span>';
};
?>
<section class="section team-section bg-white" id="team">
  <div class="container">
    <div class="text-center reveal mb-4">
      <span class="eyebrow"><?= e($teamEyebrow) ?></span>
      <h2 class="section-title mb-2"><?= e($teamTitle) ?></h2>
      <p class="text-muted mx-auto" style="max-width:640px"><?= e($teamLead) ?></p>
    </div>

    <?php if ($founder): ?>
      <div class="row g-4 align-items-center team-founder reveal">
        <div class="col-md-5 col-lg-4">
          <div class="team-photo-wrap">
            <?= $photo($founder, 'team-photo') ?>
          </div>
        </div>
        <div class="col-md-7 col-lg-8">
          <span class="eyebrow"><?= e((string) ($founder['highlight'] ?: 'Founder')) ?></span>
          <h3 class="h4 mb-1"><?= e((string) $founder['name']) ?></h3>
          <?php if (!empty($founder['designation'])): ?><p class="text-primary fw-medium mb-2"><?= e((string) $founder['designation']) ?></p><?php endif; ?>
          <?php if (!empty($founder['quote'])): ?>
            <blockquote class="team-quote">"<?= e((string) $founder['quote']) ?>"</blockquote>
          <?php endif; ?>
          <?php if (!empty($founder['bio'])): ?><p class="text-muted mb-3"><?= nl2br(e((string) $founder['bio'])) ?></p><?php endif; ?>
          <?= $socials($founder) ?>
        </div>
      </div>
    <?php endif; ?>

    <?php if ($others): ?>
      <div class="row g-3 g-md-4 mt-2 <?= $teamLayout === 'strip' ? 'team-strip' : '' ?>">
        <?php foreach ($others as $member): ?>
          <div class="col-6 col-md-4 col-lg-3 reveal">
            <article class="team-card h-100">
              <div class="team-card-photo"><?= $photo($member, 'team-card-img') ?></div>
              <div class="team-card-body">
                <h3 class="h6 mb-1"><?= e((string) $member['name']) ?></h3>
                <?php if (!empty($member['designation'])): ?><p class="team-role mb-1"><?= e((string) $member['designation']) ?></p><?php endif; ?>
                <?php if (!empty($member['highlight'])): ?><span class="chip"><?= e((string) $member['highlight']) ?></span><?php endif; ?>
                <?php if (!empty($member['bio'])): ?><p class="team-bio"><?= e(truncate_text((string) $member['bio'], 110)) ?></p><?php endif; ?>
                <?= $socials($member) ?>
              </div>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>

    <?php if (!$founder && !$others && $teamMembers): ?>
      <div class="row g-3 g-md-4">
        <?php foreach ($teamMembers as $member): ?>
          <div class="col-6 col-md-4 col-lg-3 reveal">
            <article class="team-card h-100">
              <div class="team-card-photo"><?= $photo($member, 'team-card-img') ?></div>
              <div class="team-card-body">
                <h3 class="h6 mb-1"><?= e((string) $member['name']) ?></h3>
                <?php if (!empty($member['designation'])): ?><p class="team-role mb-0"><?= e((string) $member['designation']) ?></p><?php endif; ?>
              </div>
            </article>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>
</section>
