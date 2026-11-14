<?php
/**
 * Assisted purchases: an order placed *on behalf of* a member.
 *
 * Three actors can buy for a member:
 *   - the member themselves, from the partner dashboard;
 *   - their sponsor, for anyone in their downline;
 *   - an administrator, for any member.
 *
 * All three go through one pipeline so the MLM consequences are never different depending on
 * who clicked. The order is written with member_id = the *beneficiary* (not the operator, who
 * may be an admin), which is what makes the commission engine attribute the sale to the
 * correct person and pay the correct upline.
 *
 * Commission distribution is deliberately NOT reimplemented here: a paid order is handed to
 * MlmCommissionEngine::processOrder(), the same code path a storefront order uses, and royalty
 * is evaluated by RoyaltyEngine exactly as OrderService::markPaid() does. So every commission
 * rule, level, cap and payout gate in MLM settings applies automatically.
 */
declare(strict_types=1);

namespace Saee\Lib;

use RuntimeException;

final class MemberPurchaseService
{
    /** Payment modes offered for an assisted purchase. Wallet is the only new one. */
    public const PAYMENT_MODES = [
        'wallet'        => 'Member wallet balance',
        'upi'           => 'UPI',
        'bank_transfer' => 'Bank transfer',
        'cod'           => 'Cash on delivery',
    ];

    public function __construct(private Database $db)
    {
    }

    /**
     * Who may buy for whom.
     *
     * @return array{role:string,member_id:int}
     */
    public static function actorContext(): array
    {
        if (AdminAuth::check()) {
            return ['role' => 'admin', 'member_id' => 0];
        }
        $memberId = (int) (MemberAuth::id() ?? 0);
        return ['role' => $memberId > 0 ? 'member' : 'guest', 'member_id' => $memberId];
    }

    /**
     * Members the actor is allowed to purchase for, as [id => "Name (CODE)"].
     *
     * A sponsor's list is every member below them in the tree — not just direct referrals —
     * because a purchase anywhere in the downline pays the whole upline, so the sponsor is
     * entitled to record it.
     *
     * @return array<int,string>
     */
    public function purchasableMembers(string $role, int $actorMemberId, string $search = ''): array
    {
        $params = [];
        $sql = 'SELECT m.id, m.name, m.member_code FROM members m';
        if ($role === 'member') {
            $downline = (new TeamService($this->db))->downlineIds($actorMemberId)['all'] ?? [];
            $ids = array_values(array_filter(array_map('intval', (array) $downline), static fn (int $id): bool => $id !== $actorMemberId));
            if ($ids === []) {
                return [];
            }
            $sql .= ' WHERE m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        } elseif ($role === 'admin') {
            $sql .= ' WHERE m.status <> :suspended';
            $params['suspended'] = 'suspended';
        } else {
            return [];
        }
        if ($search !== '') {
            $sql .= ($role === 'admin' ? ' AND' : ' AND') . ' (m.name LIKE :q OR m.member_code LIKE :q OR m.mobile LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY m.name LIMIT 500';

        $out = [];
        foreach ($this->db->select($sql, $params) as $row) {
            $out[(int) $row['id']] = $row['name'] . ' (' . $row['member_code'] . ')';
        }
        return $out;
    }

    /** True when the actor may place an order for this member. */
    public function canPurchaseFor(string $role, int $actorMemberId, int $memberId): bool
    {
        if ($memberId < 1) {
            return false;
        }
        if ($role === 'admin') {
            return $this->db->value('SELECT id FROM members WHERE id = :id', ['id' => $memberId]) !== null;
        }
        if ($role !== 'member' || $actorMemberId < 1) {
            return false;
        }
        if ($memberId === $actorMemberId) {
            return true;
        }
        return array_key_exists($memberId, $this->purchasableMembers('member', $actorMemberId));
    }

    /**
     * Products available to buy, for the picker.
     *
     * @return list<array>
     */
    /**
     * Active products the purchase desks offer, newest catalogue order.
     *
     * The desks filter client-side, so this returns the catalogue up to PRODUCT_LIMIT and pairs
     * it with activeProductCount(): when the two differ the UI tells the operator to narrow the
     * search instead of silently hiding products behind a 200-row cap.
     */
    public const PRODUCT_LIMIT = 500;

    public function products(string $search = ''): array
    {
        $sql = "SELECT p.id, p.name, p.sku, p.price, p.mrp, p.stock_quantity, p.status,
                       p.tax_percent, p.packaging_sizes, p.featured_image,
                       c.name AS category_name
                  FROM products p
                  LEFT JOIN product_categories c ON c.id = p.category_id
                 WHERE p.status = 'active'";
        $params = [];
        if ($search !== '') {
            $sql .= ' AND (p.name LIKE :q OR p.sku LIKE :q)';
            $params['q'] = '%' . $search . '%';
        }
        $sql .= ' ORDER BY p.name LIMIT ' . self::PRODUCT_LIMIT;
        return $this->db->select($sql, $params);
    }

    /** How many active products exist in total, so the desk can say "showing 500 of 812". */
    public function activeProductCount(): int
    {
        return (int) $this->db->value("SELECT COUNT(*) FROM products WHERE status = 'active'");
    }

    /**
     * A member's spendable wallet balance.
     */
    public function walletBalance(int $memberId): float
    {
        if ($memberId < 1) {
            return 0.0;
        }
        return (new WalletService($this->db))->availableBalance($memberId);
    }

    /**
     * Place an order on behalf of a member.
     *
     * $lines is [productId => quantity]; $data carries payment_mode, reference, notes and the
     * buyer/shipping details.
     *
     * @return array{ok:bool,message:string,order_id?:int,order_number?:string,commission?:array}
     */
    public function create(int $actorMemberId, string $role, int $beneficiaryId, array $lines, array $data): array
    {
        if (!in_array($role, ['admin', 'member'], true)) {
            return ['ok' => false, 'message' => 'You are not allowed to place this purchase.'];
        }
        if (!$this->canPurchaseFor($role, $actorMemberId, $beneficiaryId)) {
            return ['ok' => false, 'message' => 'You cannot purchase on behalf of that member.'];
        }
        $beneficiary = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $beneficiaryId]);
        if (!$beneficiary) {
            return ['ok' => false, 'message' => 'Member not found.'];
        }
        if ((string) $beneficiary['status'] !== 'active') {
            return ['ok' => false, 'message' => 'That member account is ' . $beneficiary['status'] . '; purchases are only allowed for active members.'];
        }

        $mode = (string) ($data['payment_mode'] ?? '');
        if (!array_key_exists($mode, self::PAYMENT_MODES)) {
            return ['ok' => false, 'message' => 'Choose a mode of payment.'];
        }

        // ---- build the basket from the database, never from posted prices ----------------
        $quantities = [];
        foreach ($lines as $productId => $qty) {
            $qty = (int) $qty;
            if ($qty > 0) {
                $quantities[(int) $productId] = $qty;
            }
        }
        if ($quantities === []) {
            return ['ok' => false, 'message' => 'Add at least one product with a quantity.'];
        }
        $products = [];
        $subtotal = 0.0;
        $taxTotal = 0.0;
        $items = [];
        foreach ($quantities as $productId => $qty) {
            $product = $this->db->first('SELECT * FROM products WHERE id = :id', ['id' => $productId]);
            if (!$product || (string) $product['status'] !== 'active') {
                return ['ok' => false, 'message' => 'One of the selected products is no longer available.'];
            }
            $stock = (int) $product['stock_quantity'];
            if ($stock > 0 && $qty > $stock) {
                return ['ok' => false, 'message' => 'Only ' . $stock . ' of ' . $product['name'] . ' left in stock.'];
            }
            $unit = (float) $product['price'];
            $lineTotal = round($unit * $qty, 2);
            $taxPercent = (float) ($product['tax_percent'] ?? 0);
            $lineTax = MlmCommissionEngine::compute($lineTotal, 'percent', $taxPercent);
            $subtotal += $lineTotal;
            $taxTotal += $lineTax;
            $products[] = $product;
            $items[] = [
                'product' => $product,
                'quantity' => $qty,
                'unit_price' => $unit,
                'line_total' => $lineTotal,
                'tax_amount' => $lineTax,
            ];
        }
        $subtotal = round($subtotal, 2);
        $taxTotal = round($taxTotal, 2);
        $shipping = $subtotal >= 999 ? 0.0 : 60.0;
        $grandTotal = round($subtotal + $taxTotal + $shipping, 2);

        $walletDebit = 0.0;
        if ($mode === 'wallet') {
            $balance = $this->walletBalance($beneficiaryId);
            if ($grandTotal > $balance) {
                return ['ok' => false, 'message' => 'Wallet balance is ' . money($balance) . ', this order needs ' . money($grandTotal) . '. Choose another mode of payment.'];
            }
            $walletDebit = $grandTotal;
        }

        // Paid now = the money has settled, so the order is approved straight away below and
        // commission runs. Otherwise it waits as "new", exactly like a storefront COD order.
        // Note: fulfilment status is no longer derived from payment. Both retired words that used
        // to be written here ('paid' / 'pending') were dropped from the orders.status enum, and
        // writing either one truncated the column.
        $paidNow = in_array($mode, ['wallet', 'upi', 'bank_transfer'], true);
        $paymentStatus = $paidNow ? 'paid' : 'unpaid';
        $status = ORDER_STATUS_DEFAULT;
        $paymentMethod = $mode === 'wallet' ? 'wallet' : $mode;

        $orderNumber = Sequence::orderNumber();
        $invoiceNumber = Sequence::invoiceNumber();
        $source = $role === 'admin' ? 'admin' : ($beneficiaryId === $actorMemberId ? 'member' : 'sponsor');

        // Members keep address/state on the member row and city/pincode in member_profiles.
        $profile = $this->db->first('SELECT * FROM member_profiles WHERE member_id = :m', ['m' => $beneficiaryId]) ?? [];
        $shippingAddress = [
            'full_name' => (string) ($data['customer_name'] ?? $beneficiary['name']),
            'phone' => (string) ($data['customer_mobile'] ?? $beneficiary['mobile']),
            'email' => (string) ($data['customer_email'] ?? $beneficiary['email'] ?? ''),
            'address_line1' => (string) ($data['address_line1'] ?? $beneficiary['address'] ?? $profile['address'] ?? ''),
            'address_line2' => (string) ($data['address_line2'] ?? ''),
            'city' => (string) ($data['city'] ?? $profile['district'] ?? ''),
            'district' => (string) ($profile['district'] ?? ''),
            'state' => (string) ($data['state'] ?? $beneficiary['state'] ?? $profile['state'] ?? ''),
            'pincode' => (string) ($data['pincode'] ?? $profile['pincode'] ?? ''),
        ];

        try {
            $orderId = $this->db->transaction(function (Database $db) use (
                $beneficiary, $beneficiaryId, $items, $subtotal, $taxTotal, $shipping, $grandTotal,
                $paymentMethod, $paymentStatus, $status, $orderNumber, $invoiceNumber,
                $shippingAddress, $mode, $walletDebit, $role, $actorMemberId, $source, $data, $paidNow
            ) {
                $now = date('Y-m-d H:i:s');
                $orderId = $db->insert('orders', [
                    'order_number' => $orderNumber,
                    'customer_id' => null,
                    // The beneficiary, NOT the operator: this is what makes the commission
                    // engine walk the correct upline.
                    'member_id' => $beneficiaryId,
                    'referral_member_id' => null,
                    'customer_name' => $shippingAddress['full_name'] !== '' ? $shippingAddress['full_name'] : (string) $beneficiary['name'],
                    'customer_email' => $shippingAddress['email'] !== '' ? $shippingAddress['email'] : null,
                    'customer_mobile' => $shippingAddress['phone'] !== '' ? $shippingAddress['phone'] : (string) $beneficiary['mobile'],
                    'shipping_address' => json_encode($shippingAddress, JSON_UNESCAPED_UNICODE),
                    'billing_address' => json_encode($shippingAddress, JSON_UNESCAPED_UNICODE),
                    'subtotal' => $subtotal,
                    'discount_total' => 0,
                    'shipping_total' => $shipping,
                    'tax_total' => $taxTotal,
                    'grand_total' => $grandTotal,
                    'payment_method' => $paymentMethod,
                    'payment_status' => $paymentStatus,
                    'status' => $status,
                    'notes' => trim('Assisted purchase (' . $source . '). ' . (string) ($data['notes'] ?? '')),
                    'invoice_number' => $invoiceNumber,
                    'invoice_date' => date('Y-m-d'),
                    'commission_status' => 'none',
                    'ip_address' => Request::ip(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                foreach ($items as $item) {
                    $db->insert('order_items', [
                        'order_id' => $orderId,
                        'product_id' => (int) $item['product']['id'],
                        'variant_id' => null,
                        'product_name' => (string) $item['product']['name'],
                        'product_sku' => $item['product']['sku'] ?? null,
                        'pack_size' => $item['product']['packaging_sizes'] ?? null,
                        'unit_price' => $item['unit_price'],
                        'quantity' => $item['quantity'],
                        'line_total' => $item['line_total'],
                        'tax_amount' => $item['tax_amount'],
                        // Commission basis excludes tax, as in the storefront flow.
                        'commissionable_amount' => $item['line_total'],
                        'created_at' => $now,
                    ]);
                    $db->execute(
                        'UPDATE products SET stock_quantity = CASE WHEN stock_quantity > 0 THEN stock_quantity - :q ELSE stock_quantity END WHERE id = :id',
                        ['q' => $item['quantity'], 'id' => (int) $item['product']['id']]
                    );
                }

                $actorType = $role === 'admin' ? 'admin' : 'member';
                $db->insert('order_status_history', [
                    'order_id' => $orderId, 'status' => $status,
                    'note' => 'Order placed' . ($source === 'admin' ? ' by admin on behalf of the member' : ($source === 'sponsor' ? ' by sponsor on behalf of a downline member' : '')),
                    'changed_by_type' => $actorType,
                    'changed_by_id' => $role === 'admin' ? AdminAuth::id() : $actorMemberId,
                    'created_at' => $now,
                ]);

                $db->insert('order_payments', [
                    'order_id' => $orderId,
                    'gateway' => $mode,
                    'gateway_payment_id' => $mode === 'wallet' ? 'wallet:' . $beneficiaryId . ':' . $orderId : null,
                    'amount' => $grandTotal,
                    'status' => $paidNow ? 'success' : 'pending',
                    'paid_at' => $paidNow ? $now : null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                if ($walletDebit > 0) {
                    // Debits the verified beneficiary wallet only; WalletService refuses to let a
                    // balance go negative, so a race cannot overspend.
                    (new WalletService($db))->post($beneficiaryId, 'incentive', -$walletDebit, [
                        'direction' => 'debit',
                        'status' => 'available',
                        'reference_type' => 'order',
                        'reference_id' => $orderId,
                        'description' => 'Order ' . $orderNumber . ' paid from wallet',
                    ]);
                }

                return $orderId;
            });
        } catch (RuntimeException $e) {
            return ['ok' => false, 'message' => 'The purchase could not be completed: ' . $e->getMessage()];
        }

        if ($walletDebit > 0) {
            // WalletService::post() moves the per-type balance but leaves the available/locked
            // projection alone (its other callers recalculate explicitly). Rebuild it here so
            // the member's spendable balance really drops by the order total.
            (new WalletService($this->db))->recalculate($beneficiaryId);
        }

        // ---- MLM + payout rules, through the same engine the storefront uses --------------
        // Commission is calculated and recorded the moment the purchase exists, whatever the
        // payment mode, so the sponsor line can see it immediately. Whether it is spendable yet
        // is the engine's decision: settled money releases it, cash on delivery holds it pending
        // until the order is approved or delivered.
        $commission = ['created' => 0, 'skipped' => 0, 'released' => 0, 'total' => 0.0, 'messages' => []];
        if ($paidNow) {
            // Settled money: approving the order both moves it forward and releases commission,
            // through the same path a storefront order uses rather than a second copy.
            $approved = (new OrderService($this->db))->updateStatus(
                $orderId,
                'processing',
                'Recorded as an assisted purchase, paid via ' . self::PAYMENT_MODES[$mode],
                $role === 'admin' ? 'admin' : 'member',
                $role === 'admin' ? AdminAuth::id() : $actorMemberId
            );
            $commission = $approved['commission'] ?? $commission;
        } else {
            // Cash on delivery: record now, release when the order is approved or delivered.
            // This branch bypasses OrderService::updateStatus(), so it has to evaluate royalty
            // itself; the settled branch above gets that for free through runCommission().
            $commission = (new MlmCommissionEngine($this->db))->processOrder($orderId);
            if ((int) ($commission['created'] ?? 0) > 0) {
                (new RoyaltyEngine($this->db))->evaluateMember($beneficiaryId);
            }
        }

        $operatorNote = $source === 'admin'
            ? 'Placed by an administrator'
            : ($source === 'sponsor' ? 'Placed by your sponsor' : 'Paid from your wallet');
        Notification::send('member', $beneficiaryId, 'order_placed', 'Purchase Recorded',
            'Order ' . $orderNumber . ' for ' . money($grandTotal) . ' has been recorded. ' . $operatorNote . '.',
            ['order_number' => $orderNumber, 'amount' => money($grandTotal)],
            ['link' => 'orders', 'type' => 'success', 'icon' => 'fa-bag-shopping']);
        if ($source === 'admin') {
            Notification::sendToAdmins('Assisted purchase recorded', $orderNumber . ' for ' . $beneficiary['member_code'] . ' — ' . money($grandTotal) . ' via ' . strtoupper($mode), ['link' => 'orders']);
        }

        $message = 'Purchase ' . $orderNumber . ' recorded for ' . $beneficiary['name'] . ' (' . $beneficiary['member_code'] . ') — ' . money($grandTotal) . ' via ' . self::PAYMENT_MODES[$mode] . '.';
        if ($paidNow) {
            $message .= $commission['created'] > 0
                ? ' ' . $commission['created'] . ' commission(s) totalling ' . money((float) $commission['total']) . ' distributed.'
                : ' No commission was generated: ' . ($commission['messages'][0] ?? 'no eligible upline.');
        } else {
            $message .= ' Commission will run when the order is approved or delivered.';
        }

        return [
            'ok' => true,
            'message' => $message,
            'order_id' => (int) $orderId,
            'order_number' => $orderNumber,
            'commission' => $commission,
        ];
    }
}
