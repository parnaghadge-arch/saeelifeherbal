<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\Cart;
use Saee\Lib\Request;
use Saee\Lib\Response;

api_require_post();
$result = (new Cart(\Saee\Lib\Database::instance()))->toggleWishlist(Request::int('product_id'));
if (!$result['ok']) {
    Response::error($result['message']);
}
Response::success($result['message'], ['added' => $result['added']]);
