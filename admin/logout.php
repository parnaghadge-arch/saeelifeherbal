<?php
declare(strict_types=1);
$adminPublic = true;
require __DIR__ . '/includes/_guard.php';

use Saee\Lib\AdminAuth;
use Saee\Lib\Request;
use Saee\Lib\Session;
use Saee\Lib\Url;

// Logout must be confirmed. The panel's modal asks first and then sends `confirm=1`; arriving
// here without that flag means a stale link, a bookmark or a redirect, and must not silently
// end an admin's session.
if (Request::input('confirm') !== '1') {
    $confirmTitle = 'Sign out of the admin panel?';
    $confirmMessage = 'You will need your password to get back in.';
    $confirmHref = Url::admin('logout.php?confirm=1');
    $cancelHref = Url::admin('index.php');
    require INCLUDES_PATH . '/logout-confirm.php';
    return;
}

AdminAuth::logout();
Session::success('You have been signed out of the admin panel.');
Request::redirect('login.php');
