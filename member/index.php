<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/includes/bootstrap.php';

use Saee\Lib\MemberAuth;
use Saee\Lib\Request;

Request::redirect(MemberAuth::check() ? 'dashboard.php' : 'login.php');
