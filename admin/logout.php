<?php
declare(strict_types=1);
$adminPublic = true;
require __DIR__ . '/includes/_guard.php';

use Saee\Lib\AdminAuth;
use Saee\Lib\Request;
use Saee\Lib\Session;

AdminAuth::logout();
Session::success('You have been signed out of the admin panel.');
Request::redirect('login.php');
