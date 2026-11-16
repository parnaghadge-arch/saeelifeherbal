<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\Database;
use Saee\Lib\MemberAuth;
use Saee\Lib\MemberService;
use Saee\Lib\MlmSettings;
use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\Security;
use Saee\Lib\Session;
use Saee\Lib\Url;
use Saee\Lib\Validator;

api_require_post();
api_rate_limit('member-register', 10);

$v = new Validator($_POST);
$v->label('name', 'Full name')->required('name')->min('name', 3)->max('name', 120);
$v->label('mobile', 'Mobile number')->required('mobile')->phone('mobile', true);
$v->label('email', 'Email')->email('email');
$v->label('sponsor_code', 'Sponsor ID')->max('sponsor_code', 20);
$v->label('state', 'State')->required('state');
$v->label('district', 'District')->required('district');
$v->label('pincode', 'PIN code')->pincode('pincode');
$v->label('terms', 'the terms and conditions')->accepted('terms');

if ($v->fails()) {
    Response::error($v->firstError(), $v->errors());
}

$result = (new MemberService(Database::instance()))->register([
    'name' => $v->value('name'),
    'mobile' => $v->value('mobile'),
    'email' => $v->value('email'),
    'role' => (string) Request::input('role', 'farmer'),
    'sponsor_code' => (string) Request::input('sponsor_code', ''),
    'address' => (string) Request::input('address', ''),
    'state' => (string) Request::input('state', ''),
    'district' => (string) Request::input('district', ''),
    'taluka' => (string) Request::input('taluka', ''),
    'village' => (string) Request::input('village', ''),
    'pincode' => (string) Request::input('pincode', ''),
    'date_of_birth' => (string) Request::input('date_of_birth', ''),
    'gender' => (string) Request::input('gender', ''),
], ['ip' => Request::ip(), 'source' => 'web']);

if (!$result['ok']) {
    Response::error($result['message'], $result['errors'] ?? []);
}

// Auto sign-in after registration. The password is generated as the member ID, so the
// welcome payload is the only place the plain value ever exists.
$welcome = (array) ($result['welcome'] ?? []);
MemberAuth::attempt((string) ($welcome['username'] ?? $v->value('mobile')), (string) ($welcome['password'] ?? ''), false);

// 'welcome' carries the login credentials and share link so a client can show the one-time
// "save your details" card. It is only ever present in this response.
Response::success($result['message'], [
    'member_code' => $result['member']['member_code'],
    'redirect' => Url::member('dashboard'),
    'welcome' => $result['welcome'] ?? [],
]);
