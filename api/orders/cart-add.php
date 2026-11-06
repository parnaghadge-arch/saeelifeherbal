<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\Cart;
use Saee\Lib\Request;
use Saee\Lib\Response;

api_require_post();
$productId = Request::int('product_id');
$quantity = max(1, Request::int('quantity', 1));
$variantId = Request::int('variant_id') ?: null;
if ($productId <= 0) {
    Response::error('Invalid product.');
}
$result = (new Cart(\Saee\Lib\Database::instance()))->add($productId, $quantity, $variantId);
if (!$result['ok']) {
    Response::error($result['message']);
}
Response::success($result['message'], ['count' => $result['count'] ?? 0]);
