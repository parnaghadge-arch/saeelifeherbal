<?php
/** Customer logout. */
declare(strict_types=1);

require_once __DIR__ . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\CustomerAuth;
use Saee\Lib\Request;
use Saee\Lib\Session;

CustomerAuth::logout();
\Saee\Lib\MemberAuth::logout();
Session::success('You have been signed out.');
Request::redirect('/');
