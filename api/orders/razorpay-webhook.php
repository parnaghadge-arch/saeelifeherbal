<?php
/**
 * Razorpay webhook endpoint. Signature verified with the webhook secret.
 * Events are de-duplicated in gateway_webhook_events so retries cannot double credit.
 */
declare(strict_types=1);

require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\Database;
use Saee\Lib\OrderService;
use Saee\Lib\PaymentGateway;
use Saee\Lib\Request;
use Saee\Lib\Response;

if (!Request::isPost()) {
    Response::error('Method not allowed.', [], 405);
}
$raw = Request::rawBody();
$signature = Request::header('X-Razorpay-Signature');
$provider = PaymentGateway::get('razorpay');
if (!$provider) {
    Response::error('Gateway unavailable', [], 503);
}
$verification = $provider->verifyWebhook($raw, $signature);
if (!$verification['ok']) {
    Response::error($verification['message'], [], 400);
}
$event = $verification['event'];
$eventType = (string) ($event['event'] ?? 'unknown');
$eventId = (string) ($event['payload']['payment']['entity']['id'] ?? '') ?: hash('sha256', $raw);

$db = Database::instance();
if ($db->exists('gateway_webhook_events', 'event_id = :e', ['e' => $eventId])) {
    Response::success('Duplicate event ignored.');
}
$eventRow = $db->insert('gateway_webhook_events', [
    'gateway' => 'razorpay',
    'event_id' => $eventId,
    'event_type' => $eventType,
    'payload' => $raw,
    'processed' => 0,
    'created_at' => date('Y-m-d H:i:s'),
]);

try {
    $paymentEntity = $event['payload']['payment']['entity'] ?? [];
    $gatewayOrderId = (string) ($paymentEntity['order_id'] ?? '');
    $gatewayPaymentId = (string) ($paymentEntity['id'] ?? '');
    $status = strtolower((string) ($paymentEntity['status'] ?? ''));

    if ($gatewayOrderId !== '') {
        $paymentRow = $db->first('SELECT * FROM order_payments WHERE gateway_order_id = :g', ['g' => $gatewayOrderId]);
        if ($paymentRow) {
            $orderId = (int) $paymentRow['order_id'];
            $orderService = new OrderService($db);
            if (in_array($eventType, ['payment.captured', 'order.paid'], true) || ($eventType === 'payment.authorized' && $status === 'authorized')) {
                $orderService->markPaid($orderId, [
                    'gateway' => 'razorpay',
                    'payment_id' => $gatewayPaymentId,
                    'gateway_order_id' => $gatewayOrderId,
                    'payload' => $paymentEntity,
                ]);
            } elseif ($eventType === 'payment.failed') {
                $db->update('order_payments', [
                    'status' => 'failed',
                    'failure_reason' => (string) ($paymentEntity['error_description'] ?? 'Payment failed'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ], 'id = :id', ['id' => $paymentRow['id']]);
                $db->update('orders', ['payment_status' => 'failed', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id AND payment_status <> :paid', ['id' => $orderId, 'paid' => 'paid']);
            }
        }
    }
    $db->update('gateway_webhook_events', ['processed' => 1, 'processed_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $eventRow]);
    Response::success('Webhook processed.');
} catch (\Throwable $e) {
    $db->update('gateway_webhook_events', [
        'processed' => 0,
        'error' => $e->getMessage(),
        'processed_at' => date('Y-m-d H:i:s'),
    ], 'id = :id', ['id' => $eventRow]);
    Response::error('Webhook processing failed.', [], 500);
}
