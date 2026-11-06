<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\MemberService;
use Saee\Lib\Request;
use Saee\Lib\Response;

api_require_post();
api_rate_limit('sponsor-lookup', 60);
$code = (string) Request::input('sponsor', '');
$result = (new MemberService(\Saee\Lib\Database::instance()))->lookupSponsor($code);
if (!$result['ok']) {
    Response::error($result['message'] ?? 'Sponsor ID not found.');
}
Response::success('Sponsor found', $result);
