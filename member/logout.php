<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\MemberAuth;
use Saee\Lib\Request;
use Saee\Lib\Session;
use Saee\Lib\Url;

// Logout must be confirmed. The panel's modal asks first and then sends `confirm=1`; arriving
// here without that flag means a stale link, a bookmark or a redirect, and must not silently
// end a partner's session.
if (Request::input('confirm') !== '1') {
    $confirmTitle = 'Sign out of your partner account?';
    $confirmMessage = 'You will need your password to get back in.';
    $confirmHref = Url::member('logout.php?confirm=1');
    $cancelHref = Url::member('dashboard.php');
    require INCLUDES_PATH . '/logout-confirm.php';
    return;
}

MemberAuth::logout();
Session::success('You have signed out of the partner portal.');
Request::redirect('login.php');
