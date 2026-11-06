<?php
/**
 * Razorpay checkout callback: verifies the HMAC signature server side, records the
 * payment and triggers commission generation (idempotent).
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\OrderService;
use Saee\Lib\PaymentGateway;
use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\Security;
use Saee\Lib\Session;

if (!Request::isPost()) {
    Response::error('Method not allowed.', [], 405);
}
Security::verifyCsrf();

$provider = PaymentGateway::get('razorpay');
if (!$provider) {
    Response::error('Payment gateway unavailable.');
}
$verification = $provider->verifyCallback([
    'razorpay_order_id' => Request::input('razorpay_order_id'),
    'razorpay_payment_id' => Request::input('razorpay_payment_id'),
    'razorpay_signature' => Request::input('razorpay_signature'),
]);

$orderId = Request::int('order_id');
if (!$verification['ok']) {
    \Saee\Lib\Database::instance()->update('order_payments', [
        'status' => 'failed',
        'failure_reason' => $verification['message'],
        'updated_at' => date('Y-m-d H:i:s'),
    ], 'order_id = :o AND status = :s', ['o' => $orderId, 's' => 'pending']);
    Session::error('Payment verification failed. If money was debited, contact support with your order number.');
    Request::redirect('my-orders.php');
}

$gatewayOrder = \Saee\Lib\Database::instance()->first(
    'SELECT * FROM order_payments WHERE order_id = :o AND gateway_order_id = :g',
    ['o' => $orderId, 'g' => $verification['gateway_order_id']]
);
if (!$gatewayOrder) {
    Session::error('Payment record not found. Please contact support.');
    Request::redirect('my-orders.php');
}

$result = (new OrderService(\Saee\Lib\Database::instance()))->markPaid($orderId, [
    'gateway' => 'razorpay',
    'payment_id' => $verification['payment_id'],
    'gateway_order_id' => $verification['gateway_order_id'],
    'signature' => (string) Request::input('razorpay_signature'),
]);
Session::success($result['ok'] ? 'Payment successful. Thank you for your order!' : $result['message']);
Request::redirect('order-confirmation.php?order=' . $orderId);
