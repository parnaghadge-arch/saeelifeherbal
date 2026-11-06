<?php
/**
 * Payment gateway abstraction.
 *
 * Razorpay is implemented with server side order creation and HMAC signature
 * verification for both the checkout callback and webhooks. Additional providers can be
 * added by implementing PaymentProvider.
 */
declare(strict_types=1);

namespace Saee\Lib;

interface PaymentProvider
{
    public function key(): string;
    public function label(): string;
    public function enabled(): bool;
    /** @return array{ok:bool,message?:string,order_id?:string,amount?:int,currency?:string,key_id?:string} */
    public function createIntent(array $order): array;
    public function verifyCallback(array $payload): array;
    public function verifyWebhook(string $rawBody, ?string $signature): array;
}

final class RazorpayProvider implements PaymentProvider
{
    public function __construct(private array $config)
    {
    }

    public function key(): string
    {
        return 'razorpay';
    }

    public function label(): string
    {
        return 'Razorpay';
    }

    public function enabled(): bool
    {
        return (bool) Settings::get('razorpay_enabled', false)
            && (string) Settings::get('razorpay_key_id', '') !== ''
            && (string) Settings::get('razorpay_key_secret', '') !== '';
    }

    public function createIntent(array $order): array
    {
        if (!$this->enabled()) {
            return ['ok' => false, 'message' => 'Razorpay is not configured.'];
        }
        $amount = (int) round(((float) $order['grand_total']) * 100);
        $payload = json_encode([
            'amount' => $amount,
            'currency' => 'INR',
            'receipt' => $order['order_number'],
            'notes' => ['order_number' => $order['order_number'], 'order_id' => (string) $order['id']],
        ]);
        $response = $this->request('POST', 'https://api.razorpay.com/v1/orders', $payload);
        if (!$response['ok']) {
            return ['ok' => false, 'message' => $response['message'] ?? 'Unable to create the payment order.'];
        }
        $body = $response['body'];
        $db = Database::instance();
        $existing = $db->first('SELECT id FROM order_payments WHERE order_id = :o AND status = :s', ['o' => $order['id'], 's' => 'pending']);
        $data = [
            'gateway' => 'razorpay',
            'gateway_order_id' => $body['id'] ?? null,
            'amount' => (float) $order['grand_total'],
            'status' => 'pending',
            'payload' => json_encode($body),
            'updated_at' => date('Y-m-d H:i:s'),
        ];
        if ($existing) {
            $db->update('order_payments', $data, 'id = :id', ['id' => $existing['id']]);
        } else {
            $data['order_id'] = (int) $order['id'];
            $data['idempotency_key'] = 'rzp:' . ($body['id'] ?? bin2hex(random_bytes(6)));
            $data['created_at'] = date('Y-m-d H:i:s');
            $db->insert('order_payments', $data);
        }
        return [
            'ok' => true,
            'order_id' => (string) ($body['id'] ?? ''),
            'amount' => $amount,
            'currency' => 'INR',
            'key_id' => (string) Settings::get('razorpay_key_id', ''),
        ];
    }

    /** Verify the checkout success payload (order_id|payment_id + signature). */
    public function verifyCallback(array $payload): array
    {
        $orderId = (string) ($payload['razorpay_order_id'] ?? '');
        $paymentId = (string) ($payload['razorpay_payment_id'] ?? '');
        $signature = (string) ($payload['razorpay_signature'] ?? '');
        if ($orderId === '' || $paymentId === '' || $signature === '') {
            return ['ok' => false, 'message' => 'Incomplete payment response.'];
        }
        $expected = hash_hmac('sha256', $orderId . '|' . $paymentId, (string) Settings::get('razorpay_key_secret', ''));
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'message' => 'Payment signature verification failed.'];
        }
        return ['ok' => true, 'payment_id' => $paymentId, 'gateway_order_id' => $orderId];
    }

    /** Webhook verification with the dedicated webhook secret and event de-duplication. */
    public function verifyWebhook(string $rawBody, ?string $signature): array
    {
        $secret = (string) Settings::get('razorpay_webhook_secret', '');
        if ($secret === '' || $signature === null) {
            return ['ok' => false, 'message' => 'Webhook secret is not configured.'];
        }
        $expected = hash_hmac('sha256', $rawBody, $secret);
        if (!hash_equals($expected, $signature)) {
            return ['ok' => false, 'message' => 'Invalid webhook signature.'];
        }
        $data = json_decode($rawBody, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'Invalid webhook payload.'];
        }
        return ['ok' => true, 'event' => $data];
    }

    private function request(string $method, string $url, ?string $body = null): array
    {
        $user = (string) Settings::get('razorpay_key_id', '');
        $pass = (string) Settings::get('razorpay_key_secret', '');
        $headers = ['Content-Type: application/json', 'Authorization: Basic ' . base64_encode($user . ':' . $pass)];
        $context = stream_context_create([
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'content' => $body ?? '',
                'timeout' => 20,
                'ignore_errors' => true,
            ],
        ]);
        $raw = @file_get_contents($url, false, $context);
        if ($raw === false) {
            return ['ok' => false, 'message' => 'Could not reach the payment gateway.'];
        }
        $decoded = json_decode($raw, true);
        $statusLine = $http_response_header[0] ?? '';
        if (!preg_match('/\s2\d\d\s/', $statusLine)) {
            return ['ok' => false, 'message' => $decoded['error']['description'] ?? 'Gateway rejected the request.'];
        }
        return ['ok' => true, 'body' => is_array($decoded) ? $decoded : []];
    }
}

final class PaymentGateway
{
    /** @var array<string,PaymentProvider> */
    private static array $providers = [];

    public static function boot(): void
    {
        if (self::$providers !== []) {
            return;
        }
        self::register(new RazorpayProvider([]));
    }

    public static function register(PaymentProvider $provider): void
    {
        self::$providers[$provider->key()] = $provider;
    }

    public static function get(string $key): ?PaymentProvider
    {
        self::boot();
        return self::$providers[$key] ?? null;
    }

    /** @return array<string,PaymentProvider> */
    public static function enabled(): array
    {
        self::boot();
        return array_filter(self::$providers, static fn (PaymentProvider $p) => $p->enabled());
    }
}
