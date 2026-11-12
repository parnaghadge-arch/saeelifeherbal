<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\MemberAuth;
use Saee\Lib\Request;
use Saee\Lib\Session;

MemberAuth::logout();
Session::success('You have signed out of the partner portal.');
Request::redirect('login.php');
