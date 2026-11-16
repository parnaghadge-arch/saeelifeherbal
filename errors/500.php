<?php
/**
 * Production 500 page.
 *
 * Rendered by the bootstrap exception handler. It must not touch the database, settings, the
 * layout or assets: whatever failed may be exactly those things, and this page is the last
 * resort. Everything is inline and static for that reason.
 *
 * $e is in scope when included from the handler.
 */
$ref = $GLOBALS['saee_error_ref'] ?? (defined('SAEE_ERROR_REF') ? SAEE_ERROR_REF : '');
http_response_code(500);
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta name="robots" content="noindex,nofollow">
<title>Something went wrong | Saee Life Herbal</title>
<style>
  *{box-sizing:border-box}
  body{margin:0;font-family:"Segoe UI",system-ui,-apple-system,Arial,sans-serif;background:#F6F8F6;color:#26332B;
       display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
  .card{max-width:560px;width:100%;background:#fff;border:1px solid #DFE8E1;border-radius:14px;padding:32px}
  .leaf{width:64px;height:64px;border-radius:50%;background:#E8F5E9;color:#14532D;display:flex;
        align-items:center;justify-content:center;font-size:26px;margin-bottom:14px}
  h1{font-size:21px;margin:0 0 8px}
  p{margin:0 0 12px;color:#5A6B60;line-height:1.55;font-size:15px}
  .ref{margin-top:18px;padding:12px 14px;background:#F1F5F2;border-radius:9px;font-size:13px;color:#3C4D43}
  .ref code{font-family:ui-monospace,SFMono-Regular,Menlo,monospace;font-weight:700;color:#14532D;letter-spacing:.4px}
  .actions{margin-top:20px;display:flex;gap:10px;flex-wrap:wrap}
  a.btn{display:inline-block;padding:9px 16px;border-radius:8px;font-size:14px;text-decoration:none;
        background:#14532D;color:#fff;font-weight:600}
  a.btn.alt{background:#fff;color:#14532D;border:1px solid #C9D9CF}
</style>
</head>
<body>
  <main class="card">
    <div class="leaf">&#127793;</div>
    <h1>Something went wrong</h1>
    <p>We could not complete that request. It has been logged, and nothing was lost from your
       account &mdash; please try again in a moment.</p>
    <?php if ($ref !== ''): ?>
      <div class="ref">
        If this keeps happening, send this reference to support so we can find it instantly:<br>
        <code><?= htmlspecialchars($ref, ENT_QUOTES, 'UTF-8') ?></code>
      </div>
    <?php endif; ?>
    <div class="actions">
      <a class="btn" href="/">Back to home</a>
      <a class="btn alt" href="/contact-us">Contact support</a>
    </div>
  </main>
</body>
</html>
