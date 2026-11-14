<?php
/**
 * Order lifecycle: checkout, payment confirmation, status transitions and commission hooks.
 * All money affecting writes happen inside database transactions.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class OrderService
{
    /**
     * Fulfilment only ever moves forward, and skipping a step is legitimate: a cash-on-delivery
     * order handed over at the door goes straight to delivered without anyone recording a
     * "processing" or "shipped" click first. The old table forced new -> processing -> shipped ->
     * delivered, so the status dropdown offered "Delivered" on a new order and then refused it
     * with "Cannot move an order from New to Delivered" — the order silently stayed new and the
     * member panel kept showing New after the operator believed they had delivered it.
     * Cancel is reachable from any unfinished state.
     */
    public const ALLOWED_TRANSITIONS = [
        'new' => ['processing', 'shipped', 'delivered', 'cancelled'],
        'processing' => ['shipped', 'delivered', 'cancelled'],
        'shipped' => ['delivered', 'cancelled'],
        'delivered' => [],
        'cancelled' => [],
    ];

    /**
     * Read an order status that may predate the shortened status list.
     * Legacy words are mapped onto the current set so old rows still drive the state machine.
     */
    public static function normalizeStatus(?string $status): string
    {
        $status = (string) $status;
        if (array_key_exists($status, ORDER_STATUSES)) {
            return $status;
        }
        return ORDER_STATUS_LEGACY[$status] ?? ORDER_STATUS_DEFAULT;
    }

    public function __construct(private Database $db)
    {
    }

    /**
     * Create an order from the current cart.
     */
    public function checkout(array $data): array
    {
        $cartService = new Cart($this->db);
        $cart = $cartService->current(false);
        $items = $cartService->items();
        if (!$cart || $items === []) {
            return ['ok' => false, 'message' => 'Your cart is empty.'];
        }
        $totals = $cartService->totals();
        $customer = CustomerAuth::user();
        $member = MemberAuth::user();

        $paymentMethod = in_array($data['payment_method'] ?? 'cod', array_keys(PAYMENT_METHODS), true) ? $data['payment_method'] : 'cod';
        if ($paymentMethod !== 'cod' && !$this->gatewayEnabled($paymentMethod)) {
            return ['ok' => false, 'message' => 'The selected payment method is not available right now.'];
        }
        if ($paymentMethod === 'cod') {
            $maxCod = (float) Settings::get('cod_max_amount', 0);
            if ($maxCod > 0 && $totals['grand_total'] > $maxCod) {
                return ['ok' => false, 'message' => 'Cash on Delivery is not available for orders above ' . money($maxCod) . '.'];
            }
        }

        $shipping = $data['shipping'] ?? [];
        $billing = $data['billing'] ?? $shipping;

        // Resolve the member context used for commission distribution.
        $memberId = $member['id'] ?? ($customer['member_id'] ?? null);
        $referralMemberId = null;
        if (!$memberId) {
            $ref = Session::get('referral_code') ?: ($_COOKIE['saee_ref'] ?? null);
            if ($ref) {
                $referralMemberId = $this->db->value('SELECT id FROM members WHERE referral_code = :r', ['r' => $ref]);
                $referralMemberId = $referralMemberId ? (int) $referralMemberId : null;
            }
        }

        $orderNumber = Sequence::orderNumber();
        $invoiceNumber = Sequence::invoiceNumber();
        $paymentStatus = $paymentMethod === 'cod' ? 'unpaid' : 'pending';
        // Every order starts life as "new" and waits for the admin to approve it. Whether the
        // money has arrived is a separate fact, recorded on payment_status.
        $status = ORDER_STATUS_DEFAULT;

        $orderId = $this->db->transaction(function (Database $db) use ($totals, $data, $shipping, $billing, $customer, $memberId, $referralMemberId, $paymentMethod, $orderNumber, $invoiceNumber, $paymentStatus, $status) {
            $now = date('Y-m-d H:i:s');
            $orderId = $db->insert('orders', [
                'order_number' => $orderNumber,
                'customer_id' => CustomerAuth::id(),
                'member_id' => $memberId,
                'referral_member_id' => $referralMemberId,
                'customer_name' => $shipping['full_name'] ?? ($customer['name'] ?? 'Guest'),
                'customer_email' => $shipping['email'] ?? ($customer['email'] ?? null),
                'customer_mobile' => $shipping['phone'] ?? ($customer['mobile'] ?? ''),
                'shipping_address' => json_encode($shipping, JSON_UNESCAPED_UNICODE),
                'billing_address' => json_encode($billing, JSON_UNESCAPED_UNICODE),
                'subtotal' => $totals['subtotal'],
                'discount_total' => 0,
                'shipping_total' => $totals['shipping'],
                'tax_total' => $totals['tax'],
                'grand_total' => $totals['grand_total'],
                'payment_method' => $paymentMethod,
                'payment_status' => $paymentStatus,
                'status' => $status,
                'notes' => $data['notes'] ?? null,
                'invoice_number' => $invoiceNumber,
                'invoice_date' => date('Y-m-d'),
                'commission_status' => 'none',
                'ip_address' => Request::ip(),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            foreach ($totals['items'] as $item) {
                $lineTotal = round((float) $item['unit_price'] * (int) $item['quantity'], 2);
                $db->insert('order_items', [
                    'order_id' => $orderId,
                    'product_id' => (int) $item['product_id'],
                    'variant_id' => $item['variant_id'] ? (int) $item['variant_id'] : null,
                    'product_name' => $item['name'] . ($item['variant_name'] ? ' - ' . $item['variant_name'] : ''),
                    'product_sku' => null,
                    'pack_size' => $item['pack_size'] ?? null,
                    'unit_price' => (float) $item['unit_price'],
                    'quantity' => (int) $item['quantity'],
                    'line_total' => $lineTotal,
                    'tax_amount' => MlmCommissionEngine::compute($lineTotal, 'percent', (float) $item['tax_percent']),
                    // Commission basis excludes tax so tax is never paid out as commission.
                    'commissionable_amount' => $lineTotal,
                    'created_at' => $now,
                ]);
                // Reduce stock (non-blocking when stock is not tracked).
                $db->execute(
                    'UPDATE products SET stock_quantity = CASE WHEN stock_quantity > 0 THEN stock_quantity - :q ELSE stock_quantity END WHERE id = :id',
                    ['q' => (int) $item['quantity'], 'id' => (int) $item['product_id']]
                );
            }

            $db->insert('order_status_history', [
                'order_id' => $orderId, 'status' => $status,
                'note' => 'Order placed', 'changed_by_type' => 'customer', 'changed_by_id' => CustomerAuth::id(),
                'created_at' => $now,
            ]);

            if ($paymentMethod === 'cod') {
                $db->insert('order_payments', [
                    'order_id' => $orderId, 'gateway' => 'cod', 'amount' => $totals['grand_total'],
                    'status' => 'pending', 'created_at' => $now, 'updated_at' => $now,
                ]);
            }

            // Save the address to the customer book for reuse.
            if (CustomerAuth::check() && !empty($shipping['address_line1'])) {
                $existing = $db->first(
                    'SELECT id FROM customer_addresses WHERE customer_id = :c AND address_line1 = :a AND pincode = :p LIMIT 1',
                    ['c' => CustomerAuth::id(), 'a' => $shipping['address_line1'], 'p' => $shipping['pincode'] ?? '']
                );
                if (!$existing) {
                    $db->insert('customer_addresses', [
                        'customer_id' => CustomerAuth::id(),
                        'address_type' => 'shipping',
                        'full_name' => $shipping['full_name'],
                        'phone' => $shipping['phone'],
                        'address_line1' => $shipping['address_line1'],
                        'address_line2' => $shipping['address_line2'] ?? null,
                        'city' => $shipping['city'] ?? '',
                        'district' => $shipping['district'] ?? null,
                        'state' => $shipping['state'] ?? '',
                        'pincode' => $shipping['pincode'] ?? '',
                        'is_default' => 1,
                        'created_at' => $now, 'updated_at' => $now,
                    ]);
                }
            }
            return $orderId;
        });

        $cartService->close();

        // Record commission the moment the purchase exists, exactly as an assisted purchase does,
        // so a sponsor line can see what a website order earned them straight away. The rows land
        // as pending unless the payment has already settled; approval or delivery releases them.
        // Idempotent, so the later trigger in updateStatus() simply finds nothing new to write.
        if ($memberId || $referralMemberId) {
            $this->runCommission($orderId);
        }

        Notification::send('customer', (int) (CustomerAuth::id() ?: 0), 'order_placed', 'Order Placed Successfully',
            'Your order ' . $orderNumber . ' for ' . money($totals['grand_total']) . ' has been received.',
            ['order_number' => $orderNumber, 'amount' => money($totals['grand_total'])],
            ['link' => 'orders', 'type' => 'success', 'icon' => 'fa-bag-shopping']);
        if ($memberId) {
            Notification::send('member', (int) $memberId, 'order_placed', 'Order Placed Successfully',
                'Your order ' . $orderNumber . ' for ' . money($totals['grand_total']) . ' has been received.',
                ['order_number' => $orderNumber, 'amount' => money($totals['grand_total'])],
                ['link' => 'orders', 'type' => 'success', 'icon' => 'fa-bag-shopping']);
        }
        Notification::sendToAdmins('New order received', $orderNumber . ' - ' . money($totals['grand_total']) . ' via ' . strtoupper($paymentMethod), ['template' => 'order_placed', 'link' => 'orders']);

        // Remember the orders this session placed so a guest can reopen the confirmation
        // page and invoice without a token in the URL.
        $placed = (array) Session::get('placed_orders', []);
        $placed[] = (int) $orderId;
        Session::set('placed_orders', array_slice(array_values(array_unique($placed)), -10));

        return ['ok' => true, 'order_id' => $orderId, 'order_number' => $orderNumber, 'payment_method' => $paymentMethod, 'grand_total' => $totals['grand_total']];
    }

    public function gatewayEnabled(string $method): bool
    {
        return match ($method) {
            'razorpay' => (bool) Settings::get('razorpay_enabled', false),
            'upi' => (bool) Settings::get('upi_enabled', false),
            'bank_transfer' => (bool) Settings::get('bank_transfer_enabled', false),
            'cod' => (bool) Settings::get('cod_enabled', true),
            default => false,
        };
    }

    public function enabledGateways(): array
    {
        $out = [];
        foreach (PAYMENT_METHODS as $key => $label) {
            if ($this->gatewayEnabled($key)) {
                $out[$key] = $label;
            }
        }
        return $out;
    }

    /**
     * Mark an order paid (used by gateway webhooks, verification callbacks and admin action).
     * Idempotent: repeated calls do not duplicate payments or commissions.
     */
    public function markPaid(int $orderId, array $payment = []): array
    {
        $order = $this->db->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if (!$order) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        if ($order['payment_status'] === 'paid') {
            return ['ok' => true, 'message' => 'Order already paid.', 'already' => true];
        }
        $gateway = $payment['gateway'] ?? $order['payment_method'];
        $paymentId = $payment['payment_id'] ?? null;

        if ($paymentId) {
            $dupe = $this->db->first('SELECT id FROM order_payments WHERE gateway_payment_id = :p', ['p' => $paymentId]);
            if ($dupe) {
                return ['ok' => true, 'message' => 'Payment already recorded.', 'already' => true];
            }
        }

        $this->db->transaction(function (Database $db) use ($orderId, $order, $gateway, $payment, $paymentId) {
            $now = date('Y-m-d H:i:s');
            // Recording a payment no longer moves the order forward. Fulfilment status is the
            // admin's decision (New -> Processing -> Shipped -> Delivered), and commission follows
            // that decision rather than the arrival of money.
            $db->update('orders', [
                'payment_status' => 'paid',
                'paid_at' => $now,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $orderId]);

            $existingPayment = $db->first('SELECT id FROM order_payments WHERE order_id = :o AND status = :s', ['o' => $orderId, 's' => 'pending']);
            $payload = [
                'gateway' => $gateway,
                'gateway_payment_id' => $paymentId,
                'gateway_order_id' => $payment['gateway_order_id'] ?? null,
                'gateway_signature' => $payment['signature'] ?? null,
                'amount' => (float) $order['grand_total'],
                'status' => 'success',
                'payload' => isset($payment['payload']) ? json_encode($payment['payload']) : null,
                'paid_at' => $now,
                'updated_at' => $now,
            ];
            if ($existingPayment) {
                $db->update('order_payments', $payload, 'id = :id', ['id' => $existingPayment['id']]);
            } else {
                $payload['order_id'] = $orderId;
                $payload['idempotency_key'] = 'payment:' . $orderId . ':' . ($paymentId ?: 'manual');
                $payload['created_at'] = $now;
                $db->insert('order_payments', $payload);
            }

            $db->insert('order_status_history', [
                'order_id' => $orderId, 'status' => 'paid',
                'note' => 'Payment confirmed via ' . strtoupper((string) $gateway),
                'changed_by_type' => 'system', 'changed_by_id' => null, 'created_at' => $now,
            ]);
        });

        // Settling the payment is one of the two moments commission becomes spendable (the other
        // is the order being approved or delivered). The rows are normally already recorded from
        // when the purchase was made, so this releases them; if an older order never got any,
        // processOrder() records them here. Either way it is idempotent.
        $this->runCommission($orderId);

        Notification::send('member', (int) ($order['member_id'] ?? 0), 'order_status_updated', 'Payment Confirmed',
            'Payment for order ' . $order['order_number'] . ' has been confirmed.',
            ['order_number' => $order['order_number'], 'status' => 'Paid'],
            ['link' => 'orders', 'type' => 'success', 'icon' => 'fa-circle-check']);

        return ['ok' => true, 'message' => 'Payment recorded.'];
    }

    /**
     * Generate commission for an order once it has been approved or delivered.
     * Idempotent: MlmCommissionEngine refuses an order whose commission_status is already credited.
     */
    private function runCommission(int $orderId): array
    {
        $engine = new MlmCommissionEngine($this->db);
        $result = $engine->processOrder($orderId);
        $order = $this->db->first('SELECT member_id FROM orders WHERE id = :id', ['id' => $orderId]);
        if ($order && $order['member_id']) {
            (new RoyaltyEngine($this->db))->evaluateMember((int) $order['member_id']);
        }
        return $result;
    }

    public function updateStatus(int $orderId, string $status, ?string $note = null, string $actorType = 'admin', ?int $actorId = null): array
    {
        $order = $this->db->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if (!$order) {
            return ['ok' => false, 'message' => 'Order not found.'];
        }
        if (!array_key_exists($status, ORDER_STATUSES)) {
            return ['ok' => false, 'message' => 'Unknown order status.'];
        }
        // Orders written before the status list was shortened carry old words; map them first so
        // the transition table below always sees the current vocabulary.
        $current = self::normalizeStatus($order['status']);
        if ($status === $current) {
            return ['ok' => false, 'message' => 'Order is already in that status.'];
        }
        $allowed = self::ALLOWED_TRANSITIONS[$current] ?? [];
        if (!in_array($status, $allowed, true)) {
            return ['ok' => false, 'message' => 'Cannot move an order from ' . (ORDER_STATUSES[$current] ?? $current) . ' to ' . (ORDER_STATUSES[$status] ?? $status) . '.'];
        }

        $updates = ['status' => $status, 'updated_at' => date('Y-m-d H:i:s')];
        $now = date('Y-m-d H:i:s');
        switch ($status) {
            case 'shipped':
                $updates['shipped_at'] = $now;
                break;
            case 'delivered':
                $updates['delivered_at'] = $now;
                break;
            case 'cancelled':
                $updates['cancelled_at'] = $now;
                break;
        }
        $this->db->update('orders', $updates, 'id = :id', ['id' => $orderId]);
        $this->db->insert('order_status_history', [
            'order_id' => $orderId, 'status' => $status, 'note' => $note,
            'changed_by_type' => $actorType, 'changed_by_id' => $actorId, 'created_at' => $now,
        ]);

        $result = ['ok' => true, 'message' => 'Order status updated to ' . (ORDER_STATUSES[$status] ?? $status) . '.'];

        // Delivery settles the money, for every payment method. Cash on Delivery is collected at
        // the door; for any other method an operator confirming delivery is confirming the sale
        // went through. Routed through markPaid() rather than a bare column write so the order
        // gains a payment-trail row like any other settlement — otherwise the member panel shows
        // "Paid" above an empty trail. A refund already recorded is left alone, because that money
        // genuinely went back.
        if ($status === 'delivered' && !in_array((string) $order['payment_status'], ['paid', 'refunded'], true)) {
            $settled = $this->markPaid($orderId, [
                'gateway' => (string) $order['payment_method'],
                'payment_id' => 'delivery-' . $orderId,
                'note' => 'Settled on delivery',
            ]);
            $result['payment'] = $settled;
            if (!empty($settled['ok']) && empty($settled['already'])) {
                $result['message'] .= ' Payment marked as paid.';
            }
        }

        // Commission side effects. Approval (processing) and delivery are the two triggers;
        // payment alone never pays anyone out.
        if ($status === 'cancelled') {
            // Commission is now recorded at purchase time, so a cancelled order can hold rows
            // that are recorded-but-pending as well as credited ones. Reverse both: reverseOrder()
            // already skips anything already reversed or rejected.
            if (in_array($order['commission_status'], ['credited', 'pending'], true)) {
                (new MlmCommissionEngine($this->db))->reverseOrder($orderId, 'Order cancelled');
                $result['commission'] = ['reversed' => true];
            }
        } elseif (in_array($status, ORDER_COMMISSION_STATUSES, true)) {
            $result['commission'] = $this->runCommission($orderId);
        }
        if ($order['member_id']) {
            Notification::send('member', (int) $order['member_id'], 'order_status_updated', 'Order Status Updated',
                'Your order ' . $order['order_number'] . ' is now ' . (ORDER_STATUSES[$status] ?? $status) . '.',
                ['order_number' => $order['order_number'], 'status' => ORDER_STATUSES[$status] ?? $status],
                ['link' => 'orders', 'type' => 'info', 'icon' => 'fa-truck-fast']);
        }
        Audit::log('order_status_changed', 'ecommerce', sprintf('Order %s: %s -> %s', $order['order_number'], $current, $status), 'order', $orderId);
        return $result;
    }

    public function stats(): array
    {
        $row = $this->db->first(
            "SELECT COUNT(*) AS total_orders,
                    COALESCE(SUM(CASE WHEN status IN ('processing','shipped','delivered') THEN grand_total ELSE 0 END),0) AS total_sales,
                    SUM(CASE WHEN status = 'new' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'delivered' THEN 1 ELSE 0 END) AS delivered,
                    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) AS cancelled,
                    SUM(CASE WHEN status <> 'cancelled' THEN 1 ELSE 0 END) AS active_orders
             FROM orders"
        ) ?? [];
        return [
            'total_orders' => (int) ($row['total_orders'] ?? 0),
            'total_sales' => (float) ($row['total_sales'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'delivered' => (int) ($row['delivered'] ?? 0),
            'cancelled' => (int) ($row['cancelled'] ?? 0),
            'active_orders' => (int) ($row['active_orders'] ?? 0),
            'month_sales' => (float) ($this->db->value(
                "SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status = 'paid' AND " . $this->db->monthExpression('created_at') . ' = :m',
                ['m' => date('Y-m')]
            ) ?? 0),
            'today_sales' => (float) ($this->db->value(
                "SELECT COALESCE(SUM(grand_total),0) FROM orders WHERE payment_status = 'paid' AND " . $this->db->dateExpression('created_at') . ' = :d',
                ['d' => date('Y-m-d')]
            ) ?? 0),
        ];
    }
}
