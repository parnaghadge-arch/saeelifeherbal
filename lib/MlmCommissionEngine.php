<?php
/**
 * Unilevel commission engine.
 *
 * Rules enforced here (server side only, never trusting client input):
 *  - Commission is generated only for orders that are paid (or COD delivered, if enabled)
 *    and in a completed state (paid / delivered).
 *  - Direct sponsor (level 1) and grand direct sponsor (level 2) amounts come from the
 *    product's own commission configuration - the values saved on the product form.
 *  - Calculations use integer paise - no floating point money math.
 *  - A unique idempotency key per (order item, beneficiary, level) prevents duplicate credits
 *    even if webhooks or admin actions fire repeatedly.
 *  - Cancellation / refund reverses entries instead of deleting them (immutable ledger).
 */
declare(strict_types=1);

namespace Saee\Lib;

final class MlmCommissionEngine
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Commission configuration for a product.
     *
     * The product row is the only source: the values typed on the product form are what this
     * engine pays, resolved when the order is processed. There is no separate rule register to
     * keep in step and no effective-date window that can silently exclude a product.
     */
    public function ruleForProduct(int $productId): ?array
    {
        $product = $this->db->first('SELECT * FROM products WHERE id = :id', ['id' => $productId]);
        if (!$product || (int) $product['mlm_enabled'] !== 1) {
            return null;
        }
        return [
            'product_id' => $productId,
            'direct_type' => $product['direct_commission_type'],
            'direct_value' => $product['direct_commission_value'],
            'grand_direct_type' => $product['grand_direct_commission_type'],
            'grand_direct_value' => $product['grand_direct_commission_value'],
            'commission_basis' => $product['commission_basis'],
            'royalty_eligible' => $product['royalty_eligible'],
            'is_active' => 1,
        ];
    }

    /**
     * Amount the commission percentage/fixed value is applied to.
     *
     * The product's configuration decides (products.commission_basis):
     *   selling_price → discounted unit price × quantity
     *   mrp           → printed MRP × quantity
     *   net_amount    → what the customer actually paid for the line (after discount)
     * Falls back to the line total whenever the chosen column is missing or zero.
     */
    private function basisForItem(array $item, string $basis): float
    {
        $qty = max(1.0, (float) ($item['quantity'] ?? 1));
        $lineTotal = (float) ($item['line_total'] ?? 0);
        $net = (float) ($item['commissionable_amount'] ?? 0) > 0 ? (float) $item['commissionable_amount'] : $lineTotal;

        switch ($basis) {
            case 'mrp':
                $mrp = (float) ($item['mrp'] ?? 0);
                if ($mrp <= 0 && !empty($item['product_id'])) {
                    $mrp = (float) $this->db->value('SELECT mrp FROM products WHERE id = :id', ['id' => (int) $item['product_id']]);
                }
                return $mrp > 0 ? round($mrp * $qty, 2) : $net;
            case 'net_amount':
                return $net;
            case 'selling_price':
            default:
                $unit = (float) ($item['unit_price'] ?? 0);
                return $unit > 0 ? round($unit * $qty, 2) : $net;
        }
    }

    /** Compute a commission amount in rupee float (2 dp) from a basis and a rule. */
    public static function compute(float $basis, string $type, float $value): float
    {
        $paise = self::toPaise($basis);
        if ($type === 'percent') {
            $result = (int) round($paise * $value / 100);
        } else {
            $result = (int) round($value * 100);
        }
        if ($result < 0) {
            $result = 0;
        }
        return round($result / 100, 2);
    }

    /**
     * Determine whether an order qualifies for commission generation.
     * @return array{eligible:bool, reason:string}
     */
    public function orderEligibility(array $order): array
    {
        $settings = MlmSettings::all();
        if ((int) ($settings['plan_enabled'] ?? 0) !== 1) {
            return ['eligible' => false, 'reason' => 'Compensation plan is disabled in settings.'];
        }
        $status = OrderService::normalizeStatus($order['status'] ?? null);
        // Commission follows the admin's fulfilment decision, not the arrival of money: an order
        // qualifies as soon as it is approved (processing), shipped or delivered. A brand new
        // order that nobody has looked at yet pays nobody.
        if (!in_array($status, ORDER_COMMISSION_STATUSES, true)) {
            return ['eligible' => false, 'reason' => 'Order has not been approved yet (status: ' . (ORDER_STATUSES[$status] ?? $status) . ').'];
        }
        if ($order['commission_status'] === 'credited') {
            return ['eligible' => false, 'reason' => 'Commission already credited.'];
        }
        return ['eligible' => true, 'reason' => 'Eligible'];
    }

    /**
     * Generate commission transactions for a single order.
     * Safe to call repeatedly - idempotent.
     *
     * @return array{created:int, skipped:int, total:float, messages:array}
     */
    public function processOrder(int $orderId, ?int $adminId = null): array
    {
        $settings = MlmSettings::all();
        $result = ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => []];

        $order = $this->db->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if (!$order) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => ['Order not found.']];
        }

        $eligibility = $this->orderEligibility($order);
        if (!$eligibility['eligible']) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => [$eligibility['reason']]];
        }

        $buyerMemberId = $order['member_id'] !== null ? (int) $order['member_id'] : ($order['referral_member_id'] !== null ? (int) $order['referral_member_id'] : null);
        if (!$buyerMemberId) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => ['Order has no associated member, no upline to pay.']];
        }

        $buyer = $this->db->first('SELECT id, name, member_code, sponsor_id FROM members WHERE id = :id', ['id' => $buyerMemberId]);
        if (!$buyer) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => ['Buyer member record missing.']];
        }
        // Company sponsor never earns from its own downline purchases.
        if ((int) $buyer['id'] === $this->companyMemberId()) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => ['Company account orders do not generate commissions.']];
        }

        $items = $this->db->select('SELECT * FROM order_items WHERE order_id = :o', ['o' => $orderId]);
        if ($items === []) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => ['Order has no items.']];
        }

        $holdDays = (int) ($settings['commission_hold_days'] ?? 0);
        // New commissions always start as "approved" (no wallet yet). They are released to the
        // member wallet immediately when no hold period applies, otherwise after the hold matures.
        // Release no longer waits for payment: an approved order pays out. The hold period is the
        // control for how long the company wants to sit on it. (The old commission_on_paid_only /
        // commission_on_cod switches are retired - they belonged to the payment-triggered model.)
        $autoRelease = $holdDays <= 0 && (string) ($settings['auto_credit_status'] ?? 'available') === 'available';
        $initialStatus = 'approved';
        $availableAt = $holdDays > 0 ? date('Y-m-d H:i:s', time() + $holdDays * 86400) : null;

        // Upline chain: level 1 = direct sponsor, level 2 = grand direct sponsor.
        $upline = $this->uplineChain($buyer, 2);
        if ($upline === []) {
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => ['Buyer has no sponsor in the chain.']];
        }

        $companyId = $this->companyMemberId();

        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                continue;
            }
            $rule = $this->ruleForProduct((int) $item['product_id']);
            if (!$rule) {
                $result['skipped']++;
                continue;
            }
            $basis = $this->basisForItem($item, (string) ($rule['commission_basis'] ?? 'selling_price'));

            foreach ([1 => 'direct', 2 => 'grand_direct'] as $level => $type) {
                if ($level > count($upline)) {
                    continue;
                }
                $beneficiaryId = (int) $upline[$level - 1];
                if ($beneficiaryId === (int) $buyer['id'] || $beneficiaryId === $companyId) {
                    continue; // never pay the buyer or the company itself
                }
                $beneficiary = $this->db->first('SELECT id, name, member_code, status FROM members WHERE id = :id', ['id' => $beneficiaryId]);
                if (!$beneficiary || $beneficiary['status'] === 'suspended') {
                    $result['skipped']++;
                    continue;
                }
                $typeKey = $type . '_type';
                $valueKey = $type . '_value';
                $amount = self::compute($basis, (string) $rule[$typeKey], (float) $rule[$valueKey]);
                if ($amount <= 0) {
                    $result['skipped']++;
                    continue;
                }
                $idempotency = sha1(sprintf('commission|%d|%d|%d|%s', $orderId, (int) $item['id'], $beneficiaryId, $type));

                $existing = $this->db->first('SELECT id, status FROM commission_transactions WHERE idempotency_key = :k', ['k' => $idempotency]);
                if ($existing) {
                    $result['skipped']++;
                    continue;
                }

                $txId = $this->db->insert('commission_transactions', [
                    'transaction_code' => Sequence::code('CTX', 'commission'),
                    'member_id' => $beneficiaryId,
                    'beneficiary_type' => $type,
                    'commission_type' => $type,
                    'order_id' => $orderId,
                    'order_item_id' => (int) $item['id'],
                    'product_id' => (int) $item['product_id'],
                    'product_name' => $item['product_name'],
                    'buyer_member_id' => (int) $buyer['id'],
                    'buyer_name' => $buyer['name'] . ' (' . $buyer['member_code'] . ')',
                    'level' => $level,
                    'basis_amount' => $basis,
                    'commission_type_rate' => $rule[$typeKey],
                    'rate_value' => $rule[$valueKey],
                    'amount' => $amount,
                    'status' => $initialStatus,
                    'reversed_amount' => 0,
                    'idempotency_key' => $idempotency,
                    'payment_reference' => $order['invoice_number'],
                    'remarks' => sprintf('Level %d %s on order %s', $level, str_replace('_', ' ', $type), $order['order_number']),
                    'available_at' => $availableAt,
                    'created_at' => date('Y-m-d H:i:s'),
                    'updated_at' => date('Y-m-d H:i:s'),
                ]);

                if ($autoRelease) {
                    (new WalletService($this->db))->releaseCommission($txId, 'auto credit');
                }

                $result['created']++;
                $result['total'] = round($result['total'] + $amount, 2);
                $result['messages'][] = sprintf('%s %s credited to %s', ucwords(str_replace('_', ' ', $type)), money($amount), $beneficiary['member_code']);

                Notification::send(
                    'member',
                    $beneficiaryId,
                    'commission_credited',
                    'Commission Credited',
                    ucwords(str_replace('_', ' ', $type)) . ' of ' . money($amount) . ' credited from order ' . $order['order_number'] . '.',
                    ['commission_type' => ucwords(str_replace('_', ' ', $type)), 'amount' => money($amount), 'order_number' => $order['order_number']],
                    ['link' => 'commissions', 'type' => 'success', 'icon' => 'fa-wallet']
                );
            }
        }

        $this->db->update('orders', [
            'commission_processed_at' => date('Y-m-d H:i:s'),
            'commission_status' => $result['created'] > 0 ? 'credited' : 'none',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $orderId]);

        Audit::log('commission_generated', 'mlm', sprintf('Order %s processed: %d entries totalling %s', $order['order_number'], $result['created'], money($result['total'])), 'order', $orderId);
        return $result;
    }

    /**
     * Reverse every non-reversed commission for an order (cancel / refund).
     */
    public function reverseOrder(int $orderId, string $reason = 'Order cancelled'): array
    {
        $result = ['reversed' => 0, 'total' => 0.0];
        $transactions = $this->db->select(
            "SELECT * FROM commission_transactions WHERE order_id = :o AND status NOT IN ('reversed','rejected')",
            ['o' => $orderId]
        );
        foreach ($transactions as $tx) {
            $reversed = $this->reverseCommission((int) $tx['id'], $reason);
            if ($reversed) {
                $result['reversed']++;
                $result['total'] = round($result['total'] + (float) $tx['amount'], 2);
            }
        }
        if ($result['reversed'] > 0) {
            $this->db->update('orders', ['commission_status' => 'reversed', 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $orderId]);
            Audit::log('commission_reversed', 'mlm', sprintf('Order %d reversals: %d entries, %s', $orderId, $result['reversed'], money($result['total'])), 'order', $orderId);
        }
        return $result;
    }

    public function reverseCommission(int $commissionId, string $reason): bool
    {
        return $this->db->transaction(function (Database $db) use ($commissionId, $reason) {
            $tx = $db->first('SELECT * FROM commission_transactions WHERE id = :id', ['id' => $commissionId]);
            if (!$tx || in_array($tx['status'], ['reversed', 'rejected'], true)) {
                return false;
            }
            $memberId = (int) $tx['member_id'];
            $amount = (float) $tx['amount'];
            $wereLive = in_array($tx['status'], ['available', 'paid'], true);

            $db->update('commission_transactions', [
                'status' => 'reversed',
                'reversed_amount' => $amount,
                'reversed_at' => date('Y-m-d H:i:s'),
                'reversal_reason' => mb_substr($reason, 0, 500),
                'remarks' => trim(($tx['remarks'] ?? '') . ' | reversed: ' . $reason),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $commissionId]);

            if ($wereLive) {
                // Debit the wallet; clamp to available balance to avoid negative balances.
                $wallet = new WalletService($db);
                $account = $wallet->account($memberId);
                $debit = self::toPaise($amount) / 100;
                $current = (float) $account[$tx['commission_type'] . '_balance'];
                $debit = min($debit, $current);
                if ($debit > 0) {
                    $wallet->post($memberId, (string) $tx['commission_type'], $debit, [
                        'direction' => 'debit',
                        'reference_type' => 'commission_reversal',
                        'reference_id' => $commissionId,
                        'status' => 'reversed',
                        'description' => 'Reversal: ' . $reason,
                    ]);
                }
                $wallet->recalculate($memberId);
            }

            Notification::send(
                'member',
                $memberId,
                'commission_reversed',
                'Commission Reversed',
                ucwords(str_replace('_', ' ', (string) $tx['commission_type'])) . ' of ' . money($amount) . ' was reversed: ' . $reason,
                ['commission_type' => ucwords(str_replace('_', ' ', (string) $tx['commission_type'])), 'amount' => money($amount), 'order_number' => (string) $tx['order_id'], 'reason' => $reason],
                ['link' => 'commissions', 'type' => 'warning', 'icon' => 'fa-rotate-left']
            );
            return true;
        });
    }

    /** Process every eligible order (cron friendly). */
    public function processEligibleOrders(int $limit = 100): int
    {
        $orders = $this->db->select(
            "SELECT id FROM orders
             WHERE commission_status <> 'credited'
               AND status IN ('processing','shipped','delivered')
             ORDER BY id ASC LIMIT " . (int) $limit
        );
        $count = 0;
        foreach ($orders as $order) {
            $res = $this->processOrder((int) $order['id']);
            if ($res['created'] > 0) {
                $count++;
            }
        }
        return $count;
    }

    /** Release commissions whose hold period has elapsed. */
    public function releaseMaturedCommissions(): int
    {
        $rows = $this->db->select(
            "SELECT id FROM commission_transactions
             WHERE status = 'pending' AND available_at IS NOT NULL AND available_at <= :now",
            ['now' => date('Y-m-d H:i:s')]
        );
        $wallet = new WalletService($this->db);
        $count = 0;
        foreach ($rows as $row) {
            if ($wallet->releaseCommission((int) $row['id'], 'hold period elapsed')) {
                $count++;
            }
        }
        return $count;
    }

    // ------------------------------------------------------------ internals
    /**
     * Returns sponsor ids ordered by level starting at level 1. Stops at the company
     * sponsor (which never earns) but keeps walking up to gather the configured levels.
     */
    public function uplineChain(array $buyer, int $levels): array
    {
        $chain = [];
        $current = $buyer['sponsor_id'] !== null ? (int) $buyer['sponsor_id'] : null;
        $visited = [];
        $companyId = $this->companyMemberId();
        for ($i = 0; $i < $levels; $i++) {
            if ($current === null || isset($visited[$current])) {
                break;
            }
            $visited[$current] = true;
            if ($current === $companyId) {
                // Company is the sponsor: no commission for the company, chain ends.
                break;
            }
            $sponsor = $this->db->first('SELECT id, sponsor_id, status FROM members WHERE id = :id', ['id' => $current]);
            if (!$sponsor) {
                break;
            }
            $chain[] = (int) $sponsor['id'];
            $current = $sponsor['sponsor_id'] !== null ? (int) $sponsor['sponsor_id'] : null;
        }
        return $chain;
    }

    /**
     * The company account, used to stop the commission chain and to skip company orders.
     *
     * Resolved from the configured code rather than "the first is_company row": there are
     * three company accounts and the configured one is the sponsor fallback.
     */
    public function companyMemberId(): int
    {
        $code = MlmSettings::companySponsorCode();
        $id = $this->db->value('SELECT id FROM members WHERE member_code = :c', ['c' => $code]);
        if ($id) {
            return (int) $id;
        }
        $id = $this->db->value('SELECT id FROM members WHERE is_company = 1 ORDER BY id DESC LIMIT 1');
        return $id ? (int) $id : 0;
    }

    public static function toPaise(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
