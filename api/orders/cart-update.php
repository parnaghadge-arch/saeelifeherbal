<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\Cart;
use Saee\Lib\Request;
use Saee\Lib\Response;

api_require_post();
$itemId = Request::int('item_id');
$quantity = Request::int('quantity', 1);
$cart = new Cart(\Saee\Lib\Database::instance());
$result = $cart->updateQuantity($itemId, $quantity);
if (!$result['ok']) {
    Response::error($result['message']);
}
$totals = $cart->totals();
Response::success($result['message'], ['count' => $totals['item_count'], 'total' => money($totals['grand_total'])]);
