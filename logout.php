<?php
/** Website sign out. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\AdminAuth;
use Saee\Lib\CustomerAuth;
use Saee\Lib\MemberAuth;
use Saee\Lib\Request;
use Saee\Lib\Session;

// Signing out clears EVERY login session in the browser - customer, partner and admin - so a
// shared machine ends up fully signed out. AdminAuth::logout() destroys the whole PHP session
// (including remember-me cookies of the others); the flash below lives in a fresh one.
CustomerAuth::logout();
MemberAuth::logout();
AdminAuth::logout();
Session::success('You have been signed out of all accounts.');
Request::redirect('/');
