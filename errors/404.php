<?php
/**
 * 404 page. Branded when the layout is available, plain HTML otherwise so it also works
 * before the database has been installed.
 */
declare(strict_types=1);

if (!defined('APP_ROOT')) {
    require_once dirname(__DIR__) . '/includes/bootstrap.php';
}

http_response_code(404);

$home = defined('APP_ROOT') ? \Saee\Lib\Url::to('') : '/';
$shop = defined('APP_ROOT') ? \Saee\Lib\Url::to('our-products') : '/our-products';
$contact = defined('APP_ROOT') ? \Saee\Lib\Url::to('contact-us') : '/contact-us';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,follow">
<title>Page not found | Saee Life Herbal</title>
<link rel="icon" type="image/png" href="<?= htmlspecialchars(rtrim($home, '/') . '/assets/images/logo-square.png', ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(rtrim($home, '/') . '/assets/vendor/bootstrap/bootstrap.min.css', ENT_QUOTES) ?>">
<link rel="stylesheet" href="<?= htmlspecialchars(rtrim($home, '/') . '/assets/vendor/fontawesome/css/all.min.css', ENT_QUOTES) ?>">
<style>
  :root { --brand: #14532D; --brand-soft: #E8F5E9; }
  body { font-family: "Segoe UI", system-ui, -apple-system, Arial, sans-serif; background: #F6F8F6; color: #26332B; }
  .hero { max-width: 640px; margin: 0 auto; text-align: center; padding: 96px 20px 64px; }
  .code { font-size: 92px; line-height: 1; font-weight: 800; color: var(--brand); letter-spacing: -2px; }
  .leaf { display: inline-flex; align-items: center; justify-content: center; width: 76px; height: 76px; border-radius: 50%; background: var(--brand-soft); color: var(--brand); font-size: 30px; margin-bottom: 8px; }
  h1 { font-size: 26px; margin: 8px 0 10px; }
  p { color: #5A6B60; margin-bottom: 22px; }
  .btn-brand { background: var(--brand); border-color: var(--brand); color: #fff; }
  .btn-brand:hover { background: #0F3E22; border-color: #0F3E22; color: #fff; }
  .links { margin-top: 28px; font-size: 14px; color: #7A8A80; }
  .links a { color: #14532D; text-decoration: none; margin: 0 8px; }
</style>
</head>
<body>
  <main class="hero">
    <div class="leaf"><i class="fa-solid fa-seedling"></i></div>
    <div class="code">404</div>
    <h1>We couldn't find that page</h1>
    <p>The link may be broken, or the page may have moved. Let's get you back to something growing.</p>
    <div class="d-flex gap-2 justify-content-center flex-wrap">
      <a class="btn btn-brand" href="<?= htmlspecialchars($home, ENT_QUOTES) ?>"><i class="fa-solid fa-house me-1"></i>Back to home</a>
      <a class="btn btn-outline-secondary" href="<?= htmlspecialchars($shop, ENT_QUOTES) ?>">Browse products</a>
    </div>
    <div class="links">
      <a href="<?= htmlspecialchars($contact, ENT_QUOTES) ?>">Contact support</a>·
      <a href="<?= htmlspecialchars(rtrim($home, '/') . '/faqs', ENT_QUOTES) ?>">FAQs</a>
    </div>
  </main>
</body>
</html>
