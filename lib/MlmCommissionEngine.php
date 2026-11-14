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

        $plan = $this->commissionPlan($order);
        if ($plan['aborted']) {
            // Structural reason nobody can be paid (no member, no items, no upline). Left
            // unstamped so the order stays eligible if its sponsor chain is fixed later.
            return ['created' => 0, 'skipped' => 0, 'total' => 0.0, 'messages' => $plan['messages']];
        }
        $buyer = $plan['buyer'];
        $result['skipped'] = $plan['skipped'];

        $holdDays = (int) ($settings['commission_hold_days'] ?? 0);
        // New commissions always start as "approved" (no wallet yet). They are released to the
        // member wallet immediately when no hold period applies, otherwise after the hold matures.
        // Release no longer waits for payment: an approved order pays out. The hold period is the
        // control for how long the company wants to sit on it.
        $autoRelease = $holdDays <= 0 && (string) ($settings['auto_credit_status'] ?? 'available') === 'available';
        $initialStatus = 'approved';
        $availableAt = $holdDays > 0 ? date('Y-m-d H:i:s', time() + $holdDays * 86400) : null;

        foreach ($plan['entries'] as $entry) {
            $beneficiaryId = (int) $entry['beneficiary_id'];
            $type = (string) $entry['type'];
            $level = (int) $entry['level'];
            $amount = (float) $entry['amount'];

            $idempotency = sha1(sprintf('commission|%d|%d|%d|%s', $orderId, (int) $entry['order_item_id'], $beneficiaryId, $type));
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
                'order_item_id' => (int) $entry['order_item_id'],
                'product_id' => (int) $entry['product_id'],
                'product_name' => $entry['product_name'],
                'buyer_member_id' => (int) $buyer['id'],
                'buyer_name' => $buyer['name'] . ' (' . $buyer['member_code'] . ')',
                'level' => $level,
                'basis_amount' => $entry['basis'],
                'commission_type_rate' => $entry['rate_type'],
                'rate_value' => $entry['rate_value'],
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
            $result['messages'][] = sprintf('%s %s credited to %s', ucwords(str_replace('_', ' ', $type)), money($amount), $entry['beneficiary_code']);

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

        $this->db->update('orders', [
            'commission_processed_at' => date('Y-m-d H:i:s'),
            'commission_status' => $result['created'] > 0 ? 'credited' : 'none',
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $orderId]);

        Audit::log('commission_generated', 'mlm', sprintf('Order %s processed: %d entries totalling %s', $order['order_number'], $result['created'], money($result['total'])), 'order', $orderId);
        return $result;
    }

    /**
     * Work out who would be paid what for an order, WITHOUT writing anything.
     *
     * This is the single source of the commission maths. processOrder() credits exactly this
     * plan and projectOrder() displays exactly this plan, so a "future commission" figure shown
     * on screen is the figure that will actually be credited once the order is approved. If the
     * two were computed separately they would eventually disagree, and the projection would be
     * a number nobody ever receives.
     *
     * @return array{aborted:bool, buyer:?array, entries:list<array>, skipped:int, messages:list<string>}
     */
    public function commissionPlan(array $order): array
    {
        $out = ['aborted' => false, 'buyer' => null, 'entries' => [], 'skipped' => 0, 'messages' => []];
        $orderId = (int) $order['id'];

        $buyerMemberId = $order['member_id'] !== null ? (int) $order['member_id']
            : ($order['referral_member_id'] !== null ? (int) $order['referral_member_id'] : null);
        if (!$buyerMemberId) {
            $out['aborted'] = true;
            $out['messages'][] = 'Order has no associated member, no upline to pay.';
            return $out;
        }
        $buyer = $this->db->first('SELECT id, name, member_code, sponsor_id FROM members WHERE id = :id', ['id' => $buyerMemberId]);
        if (!$buyer) {
            $out['aborted'] = true;
            $out['messages'][] = 'Buyer member record missing.';
            return $out;
        }
        $out['buyer'] = $buyer;

        $items = $this->db->select('SELECT * FROM order_items WHERE order_id = :o', ['o' => $orderId]);
        if ($items === []) {
            $out['aborted'] = true;
            $out['messages'][] = 'Order has no items.';
            return $out;
        }
        $upline = $this->uplineChain($buyer, 2);
        if ($upline === []) {
            $out['aborted'] = true;
            $out['messages'][] = 'Buyer has no sponsor in the chain.';
            return $out;
        }

        foreach ($items as $item) {
            if (empty($item['product_id'])) {
                continue;
            }
            $rule = $this->ruleForProduct((int) $item['product_id']);
            if (!$rule) {
                $out['skipped']++;
                continue;
            }
            $basis = $this->basisForItem($item, (string) ($rule['commission_basis'] ?? 'selling_price'));

            foreach ([1 => 'direct', 2 => 'grand_direct'] as $level => $type) {
                if ($level > count($upline)) {
                    continue;
                }
                $beneficiaryId = (int) $upline[$level - 1];
                if ($beneficiaryId === (int) $buyer['id']) {
                    continue; // never pay the buyer for their own purchase
                }
                $beneficiary = $this->db->first('SELECT id, name, member_code, status FROM members WHERE id = :id', ['id' => $beneficiaryId]);
                if (!$beneficiary || $beneficiary['status'] === 'suspended') {
                    $out['skipped']++;
                    continue;
                }
                $typeKey = $type . '_type';
                $valueKey = $type . '_value';
                $amount = self::compute($basis, (string) $rule[$typeKey], (float) $rule[$valueKey]);
                if ($amount <= 0) {
                    $out['skipped']++;
                    continue;
                }
                $out['entries'][] = [
                    'beneficiary_id' => $beneficiaryId,
                    'beneficiary_code' => (string) $beneficiary['member_code'],
                    'beneficiary_name' => (string) $beneficiary['name'],
                    'level' => $level,
                    'type' => $type,
                    'order_item_id' => (int) $item['id'],
                    'product_id' => (int) $item['product_id'],
                    'product_name' => (string) $item['product_name'],
                    'basis' => $basis,
                    'rate_type' => (string) $rule[$typeKey],
                    'rate_value' => (float) $rule[$valueKey],
                    'amount' => $amount,
                ];
            }
        }
        return $out;
    }

    /**
     * Future commission: what an order that is recorded but not yet approved will pay out.
     *
     * Nothing is written. The order stays untouched, no wallet moves and no reversal
     * bookkeeping is created, because an order can still be cancelled before anyone looks at
     * it. The figure is a projection of the same plan processOrder() will credit, labelled
     * clearly as future so nobody mistakes it for money they can withdraw.
     *
     * @return array{future:bool, eligible:bool, reason:string, entries:list<array>,
     *               total:float, by_member:array<int,array>, skipped:int}
     */
    public function projectOrder(int $orderId): array
    {
        // Built per return on purpose: PHP's array `+` keeps the LEFT operand's value for a
        // duplicate key, so `$empty + ['reason' => ...]` silently throws the reason away.
        $nothing = static function (string $reason): array {
            return ['future' => false, 'eligible' => false, 'reason' => $reason, 'entries' => [],
                    'total' => 0.0, 'by_member' => [], 'skipped' => 0];
        };

        $order = $this->db->first('SELECT * FROM orders WHERE id = :id', ['id' => $orderId]);
        if (!$order) {
            return $nothing('Order not found.');
        }

        $settings = MlmSettings::all();
        if ((int) ($settings['plan_enabled'] ?? 0) !== 1) {
            return $nothing('Compensation plan is disabled in settings.');
        }
        $status = OrderService::normalizeStatus($order['status'] ?? null);
        // A cancelled order will never pay out, so it must not advertise future commission.
        if ($status === 'cancelled') {
            return $nothing('Order cancelled - no commission will be paid on it.');
        }
        if ((string) $order['commission_status'] === 'credited') {
            // Already paid out - that is real commission, not a projection.
            return $nothing('Commission already credited.');
        }

        $plan = $this->commissionPlan($order);
        if ($plan['aborted']) {
            return $nothing($plan['messages'][0] ?? 'No commission applies to this order.');
        }

        $total = 0.0;
        $byMember = [];
        foreach ($plan['entries'] as $entry) {
            $total = round($total + (float) $entry['amount'], 2);
            $id = (int) $entry['beneficiary_id'];
            if (!isset($byMember[$id])) {
                $byMember[$id] = [
                    'member_id' => $id,
                    'member_code' => $entry['beneficiary_code'],
                    'member_name' => $entry['beneficiary_name'],
                    'amount' => 0.0,
                    'entries' => [],
                ];
            }
            $byMember[$id]['amount'] = round($byMember[$id]['amount'] + (float) $entry['amount'], 2);
            $byMember[$id]['entries'][] = $entry;
        }

        return [
            // "future" means the order has not reached an approving status yet, so nothing has
            // been credited. Once it is approved the same amounts appear as real commission.
            'future' => !in_array($status, ORDER_COMMISSION_STATUSES, true),
            'eligible' => true,
            'reason' => in_array($status, ORDER_COMMISSION_STATUSES, true)
                ? 'Awaiting commission processing.'
                : 'Pays out when this order is approved or delivered.',
            'entries' => $plan['entries'],
            'total' => $total,
            'by_member' => $byMember,
            'skipped' => $plan['skipped'],
        ];
    }

    /**
     * Total future commission across every order that could still pay this member.
     *
     * Sums the same projection projectOrder() shows per order, so the profile total and the
     * per-order figures always agree. Bounded to the most recent orders: a member with years of
     * history should not make the commissions page run a projection per order ever recorded.
     *
     * @return array{total:float, orders:int, entries:list<array>}
     */
    public function futureCommissionForMember(int $memberId, int $limit = 100): array
    {
        $out = ['total' => 0.0, 'orders' => 0, 'entries' => []];
        if ($memberId < 1) {
            return $out;
        }
        // A member earns on their DOWNLINE's purchases, not their own, and only two levels
        // deep. Selecting the member's own orders here would always total zero.
        $downline = (new TeamService($this->db))->downlineIds($memberId, 2);
        $buyerIds = array_values(array_unique(array_merge(
            $downline['levels'][1] ?? [],
            $downline['levels'][2] ?? []
        )));
        if ($buyerIds === []) {
            return $out;
        }
        $placeholders = implode(',', array_fill(0, count($buyerIds), '?'));
        // Only orders that are neither cancelled nor already credited can still pay out.
        $orders = $this->db->select(
            "SELECT * FROM orders
              WHERE member_id IN ($placeholders)
                AND COALESCE(commission_status, '') <> 'credited'
              ORDER BY id DESC
              LIMIT " . max(1, $limit),
            $buyerIds
        );
        foreach ($orders as $order) {
            $projection = $this->projectOrder((int) $order['id']);
            $mine = $projection['by_member'][$memberId] ?? null;
            if (!$mine || (float) $mine['amount'] <= 0) {
                continue;
            }
            $out['total'] = round($out['total'] + (float) $mine['amount'], 2);
            $out['orders']++;
            foreach ($mine['entries'] as $entry) {
                $out['entries'][] = $entry + [
                    'order_id' => (int) $order['id'],
                    'order_number' => (string) $order['order_number'],
                    'future' => (bool) $projection['future'],
                ];
            }
        }
        return $out;
    }

    /**
     * Company-wide future commission across the orders still waiting to be approved.
     *
     * Bounded, and the bound is reported back so the admin screen can say so. Projecting every
     * order ever recorded would be needlessly expensive for a headline number; the most recent
     * un-approved orders are where the outstanding commission actually is.
     *
     * @return array{total:float, orders:int, capped:bool}
     */
    public function futureCommissionTotal(int $limit = 200): array
    {
        $limit = max(1, $limit);
        $orders = $this->db->select(
            "SELECT * FROM orders
              WHERE COALESCE(commission_status, '') <> 'credited'
              ORDER BY id DESC
              LIMIT " . ($limit + 1)
        );
        $capped = count($orders) > $limit;
        if ($capped) {
            $orders = array_slice($orders, 0, $limit);
        }
        $total = 0.0;
        $count = 0;
        foreach ($orders as $order) {
            $projection = $this->projectOrder((int) $order['id']);
            if ((float) ($projection['total'] ?? 0) <= 0) {
                continue;
            }
            $total = round($total + (float) $projection['total'], 2);
            $count++;
        }
        return ['total' => $total, 'orders' => $count, 'capped' => $capped];
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
     * Returns sponsor ids ordered by level starting at level 1.
     *
     * The chain used to stop dead at the company account on the theory that the company never
     * earns. That left every member sponsored directly by SAEE00003 with an empty upline, so
     * their orders generated no commission at all — not for the company, and not for anyone
     * above it. The company is now an ordinary link in the chain and is paid like any other
     * sponsor; the walk still ends on its own because the top company account has no sponsor.
     */
    public function uplineChain(array $buyer, int $levels): array
    {
        $chain = [];
        $current = $buyer['sponsor_id'] !== null ? (int) $buyer['sponsor_id'] : null;
        $visited = [];
        for ($i = 0; $i < $levels; $i++) {
            if ($current === null || isset($visited[$current])) {
                break;
            }
            $visited[$current] = true;
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
