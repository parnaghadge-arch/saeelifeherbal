<?php
/**
 * Wallet ledger service.
 *
 * Balances are *derived* projections of the immutable wallet_transactions ledger.
 * Every mutation goes through a database transaction, writes a ledger row and updates
 * the cached projection on wallet_accounts. Amounts are handled as integer paise to
 * avoid floating point drift, then stored as DECIMAL(14,2).
 */
declare(strict_types=1);

namespace Saee\Lib;

final class WalletService
{
    public function __construct(private Database $db)
    {
    }

    public function account(int $memberId, bool $createIfMissing = true): ?array
    {
        $account = $this->db->first('SELECT * FROM wallet_accounts WHERE member_id = :m', ['m' => $memberId]);
        if ($account) {
            return $account;
        }
        if (!$createIfMissing) {
            return null;
        }
        $this->db->insert('wallet_accounts', [
            'member_id' => $memberId,
            'created_at' => date('Y-m-d H:i:s'),
            'updated_at' => date('Y-m-d H:i:s'),
        ]);
        return $this->db->first('SELECT * FROM wallet_accounts WHERE member_id = :m', ['m' => $memberId]);
    }

    /**
     * Credit (or debit) a member wallet and write the ledger entry.
     *
     * @param array $opts [reference_type, reference_id, status, description, created_by_admin_id,
     *                     transaction_code, direction]
     */
    public function post(
        int $memberId,
        string $walletType,
        float $amount,
        array $opts = []
    ): array {
        if (!in_array($walletType, ['direct', 'grand_direct', 'royalty', 'incentive'], true)) {
            throw new \InvalidArgumentException('Unknown wallet type: ' . $walletType);
        }
        $paise = self::toPaise($amount);
        if ($paise === 0) {
            throw new \InvalidArgumentException('Wallet amount must be non-zero.');
        }
        $direction = $opts['direction'] ?? ($paise > 0 ? 'credit' : 'debit');
        $amount = self::fromPaise(abs($paise));
        $status = $opts['status'] ?? 'available';

        return $this->db->transaction(function (Database $db) use ($memberId, $walletType, $amount, $opts, $direction, $status) {
            $account = $this->account($memberId);
            $column = $walletType . '_balance';
            $balanceBefore = (float) $account[$column];
            $delta = $direction === 'credit' ? $amount : -$amount;
            $balanceAfter = self::fromPaise(self::toPaise($balanceBefore) + self::toPaise($delta));
            if ($balanceAfter < 0) {
                throw new \RuntimeException('Insufficient wallet balance for this operation.');
            }

            $updates = [
                $column => $balanceAfter,
                'last_activity_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ];
            if ($direction === 'credit') {
                $updates['total_earned'] = self::fromPaise(self::toPaise((float) $account['total_earned']) + self::toPaise($amount));
            } else {
                $updates['total_reversed'] = self::fromPaise(self::toPaise((float) $account['total_reversed']) + self::toPaise($amount));
            }
            $db->update('wallet_accounts', $updates, 'member_id = :m', ['m' => $memberId]);

            $code = $opts['transaction_code'] ?? Sequence::code('WTX', 'wallet');
            $id = $db->insert('wallet_transactions', [
                'transaction_code' => $code,
                'member_id' => $memberId,
                'wallet_type' => $walletType,
                'direction' => $direction,
                'amount' => $amount,
                'balance_after' => $balanceAfter,
                'reference_type' => $opts['reference_type'] ?? 'manual',
                'reference_id' => $opts['reference_id'] ?? null,
                'status' => $status,
                'description' => isset($opts['description']) ? mb_substr((string) $opts['description'], 0, 255) : null,
                'created_by_admin_id' => $opts['created_by_admin_id'] ?? AdminAuth::id(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            return [
                'wallet_transaction_id' => $id,
                'transaction_code' => $code,
                'balance_after' => $balanceAfter,
                'amount' => $amount,
            ];
        });
    }

    /**
     * Recalculate the pending / approved / available projections from the ledger.
     * Available = all credits with status available|paid (earned) minus withdrawals taken.
     */
    public function recalculate(int $memberId): array
    {
        return $this->db->transaction(function (Database $db) use ($memberId) {
            $this->account($memberId);

            $sums = [];
            $grossCredits = 0.0;
            foreach (['direct', 'grand_direct', 'royalty', 'incentive'] as $type) {
                $row = $db->first(
                    "SELECT
                        COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE -amount END), 0) AS net,
                        COALESCE(SUM(CASE WHEN direction = 'credit' THEN amount ELSE 0 END), 0) AS credited
                     FROM wallet_transactions WHERE member_id = :m AND wallet_type = :t AND status <> 'pending'",
                    ['m' => $memberId, 't' => $type]
                );
                $sums[$type] = self::fromPaise(self::toPaise((float) ($row['net'] ?? 0)));
                $grossCredits += (float) ($row['credited'] ?? 0);
            }

            $pending = (float) $db->value(
                "SELECT COALESCE(SUM(amount),0) FROM commission_transactions WHERE member_id = :m AND status = 'pending' AND reversed_amount = 0",
                ['m' => $memberId]
            );
            $approved = (float) $db->value(
                "SELECT COALESCE(SUM(amount),0) FROM commission_transactions WHERE member_id = :m AND status = 'approved' AND reversed_amount = 0",
                ['m' => $memberId]
            );

            // Remaining balance per category (credits minus debits, e.g. paid withdrawals).
            $balance = self::fromPaise(array_sum(array_map([self::class, 'toPaise'], $sums)));
            // Lifetime earnings are gross credits; withdrawals show up in the ledger debits.
            $earned = self::fromPaise(self::toPaise($grossCredits));
            $withdrawn = self::fromPaise(self::toPaise((float) $db->value(
                "SELECT COALESCE(SUM(net_amount),0) FROM payout_requests WHERE member_id = :m AND status = 'paid'",
                ['m' => $memberId]
            )));
            // Requests still being processed are reserved but not yet debited from the ledger,
            // so only those have to be subtracted from the balance. 'processing' means the
            // admin has downloaded the payout report and payment is in progress — the amount
            // stays locked until markPaid() debits it.
            $lockedPending = self::fromPaise(self::toPaise((float) $db->value(
                "SELECT COALESCE(SUM(net_amount),0) FROM payout_requests WHERE member_id = :m AND status IN ('pending','approved','processing')",
                ['m' => $memberId]
            )));
            $available = self::fromPaise(self::toPaise($balance) - self::toPaise($lockedPending));
            if ($available < 0) {
                $available = 0.0;
            }

            $db->update('wallet_accounts', [
                'direct_balance' => $sums['direct'],
                'grand_direct_balance' => $sums['grand_direct'],
                'royalty_balance' => $sums['royalty'],
                'incentive_balance' => $sums['incentive'],
                'pending_balance' => $pending,
                'approved_balance' => $approved,
                'available_balance' => $available,
                'total_earned' => $earned,
                'total_withdrawn' => $withdrawn,
                'locked_balance' => $lockedPending,
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'member_id = :m', ['m' => $memberId]);

            $db->update('members', ['total_earned' => $earned], 'id = :m', ['m' => $memberId]);

            return $this->account($memberId);
        });
    }

    public function availableBalance(int $memberId): float
    {
        $account = $this->account($memberId);
        return (float) ($account['available_balance'] ?? 0);
    }

    /**
     * Convert a commission from pending to available (after the hold period or approval).
     */
    public function releasePending(int $memberId): int
    {
        $rows = $this->db->select(
            "SELECT * FROM commission_transactions WHERE member_id = :m AND status IN ('pending','approved') AND reversed_amount = 0 ORDER BY id",
            ['m' => $memberId]
        );
        $count = 0;
        foreach ($rows as $row) {
            $this->releaseCommission((int) $row['id'], 'system');
            $count++;
        }
        return $count;
    }

    /** Move a single commission transaction to available and post the wallet credit. */
    public function releaseCommission(int $commissionId, string $actor = 'system'): bool
    {
        return $this->db->transaction(function (Database $db) use ($commissionId, $actor) {
            $tx = $db->first('SELECT * FROM commission_transactions WHERE id = :id', ['id' => $commissionId]);
            if (!$tx || !in_array($tx['status'], ['pending', 'approved'], true)) {
                return false;
            }
            $db->update('commission_transactions', [
                'status' => 'available',
                'available_at' => date('Y-m-d H:i:s'),
                'credited_at' => date('Y-m-d H:i:s'),
                'remarks' => trim(($tx['remarks'] ?? '') . ' | released by ' . $actor),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $commissionId]);

            $this->post((int) $tx['member_id'], (string) $tx['commission_type'], (float) $tx['amount'], [
                'reference_type' => 'commission',
                'reference_id' => $commissionId,
                'status' => 'available',
                'description' => ucwords(str_replace('_', ' ', (string) $tx['commission_type'])) . ' earned from order ' . ($tx['order_id'] ?: ''),
            ]);
            $this->recalculate((int) $tx['member_id']);
            return true;
        });
    }

    /**
     * Record that a commission entry has been handed to the member - typically cash over the
     * counter by an accountant.
     *
     * The entry moves to 'paid' and the amount is debited from the wallet, so the balance drops
     * by exactly what was handed over. Marking some of a member's entries paid leaves the rest
     * available, which is what makes the remaining figure on the wallet and commission screens
     * mean something.
     *
     * Only entries that are actually spendable can be paid out: a pending or approved entry has
     * not been credited to the wallet yet, so paying it would hand over money the ledger never
     * gave them. Reversed entries are excluded for the same reason.
     *
     * @return array{ok:bool,message:string,amount:float,remaining:float}
     */
    public function markCommissionPaid(
        int $commissionId,
        string $mode = 'cash',
        string $reference = '',
        string $actor = 'admin'
    ): array {
        return $this->db->transaction(function (Database $db) use ($commissionId, $mode, $reference, $actor) {
            $nothing = static fn (string $m): array => ['ok' => false, 'message' => $m, 'amount' => 0.0, 'remaining' => 0.0];

            $tx = $db->first('SELECT * FROM commission_transactions WHERE id = :id', ['id' => $commissionId]);
            if (!$tx) {
                return $nothing('Commission entry not found.');
            }
            if ((string) $tx['status'] === 'paid') {
                return $nothing('This commission is already marked as paid.');
            }
            if (!in_array((string) $tx['status'], ['available'], true)) {
                return $nothing('Only commission that has been credited to the wallet can be marked as paid. This entry is ' . (string) $tx['status'] . '.');
            }

            $memberId = (int) $tx['member_id'];
            $amount = self::fromPaise(self::toPaise((float) $tx['amount']));
            $type = (string) $tx['commission_type'];

            $db->update('commission_transactions', [
                'status' => 'paid',
                'paid_at' => date('Y-m-d H:i:s'),
                'remittance_reference' => $reference !== '' ? mb_substr($reference, 0, 80) : null,
                'remarks' => trim(($tx['remarks'] ?? '') . ' | paid via ' . $mode . ' by ' . $actor),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $commissionId]);

            // Debit the wallet by what was handed over, clamped to what the category actually
            // holds so a rounding difference can never push a balance negative.
            $account = $this->account($memberId);
            $held = (float) ($account[$type . '_balance'] ?? 0);
            $debit = min($amount, $held);
            if ($debit > 0) {
                $this->post($memberId, $type, $debit, [
                    'direction' => 'debit',
                    'reference_type' => 'commission_payout',
                    'reference_id' => $commissionId,
                    'status' => 'paid',
                    'description' => ucwords(str_replace('_', ' ', $type)) . ' paid via ' . $mode
                        . ($reference !== '' ? ' (' . $reference . ')' : ''),
                ]);
            }
            $this->recalculate($memberId);

            Audit::log(
                'commission_paid',
                'mlm',
                sprintf('Commission #%d (%s) marked paid via %s%s', $commissionId, money($amount), $mode, $reference !== '' ? ' ref ' . $reference : ''),
                'commission_transactions',
                $commissionId
            );
            Notification::send(
                'member',
                $memberId,
                'commission_paid',
                'Commission Paid',
                ucwords(str_replace('_', ' ', $type)) . ' of ' . money($amount) . ' has been paid to you via ' . $mode . '.',
                ['commission_type' => ucwords(str_replace('_', ' ', $type)), 'amount' => money($amount), 'mode' => $mode],
                ['link' => 'commissions', 'type' => 'success', 'icon' => 'fa-money-bill-wave']
            );

            return [
                'ok' => true,
                'message' => 'Marked ' . money($amount) . ' as paid via ' . $mode . '.',
                'amount' => $amount,
                'remaining' => $this->availableBalance($memberId),
            ];
        });
    }

    /**
     * Pay out everything a member can currently withdraw, oldest entry first.
     *
     * This is the cash-over-the-counter case: the accountant counts out the whole balance and
     * wants one action, not one click per ledger row. Stops at the first entry that cannot be
     * paid and reports what was left behind.
     *
     * @return array{ok:bool,message:string,paid:int,total:float,remaining:float,skipped:int}
     */
    public function markMemberCommissionPaid(
        int $memberId,
        string $mode = 'cash',
        string $reference = '',
        string $actor = 'admin'
    ): array {
        $rows = $this->db->select(
            "SELECT id FROM commission_transactions
              WHERE member_id = :m AND status = 'available' AND reversed_amount = 0
              ORDER BY id ASC",
            ['m' => $memberId]
        );
        $paid = 0; $total = 0.0; $skipped = 0;
        foreach ($rows as $row) {
            $res = $this->markCommissionPaid((int) $row['id'], $mode, $reference, $actor);
            if (!empty($res['ok'])) {
                $paid++;
                $total = round($total + (float) $res['amount'], 2);
            } else {
                $skipped++;
            }
        }
        return [
            'ok' => $paid > 0,
            'message' => $paid > 0
                ? sprintf('Marked %d entr%s totalling %s as paid via %s.', $paid, $paid === 1 ? 'y' : 'ies', money($total), $mode)
                : 'Nothing was available to pay out.',
            'paid' => $paid,
            'total' => $total,
            'remaining' => $this->availableBalance($memberId),
            'skipped' => $skipped,
        ];
    }

    // ------------------------------------------------------------ utilities
    public static function toPaise(float|int|string|null $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }

    public static function fromPaise(int $paise): float
    {
        return round($paise / 100, 2);
    }

    /** Sum of wallet type balances for display. */
    public static function summary(array $account): array
    {
        return [
            'direct' => (float) $account['direct_balance'],
            'grand_direct' => (float) $account['grand_direct_balance'],
            'royalty' => (float) $account['royalty_balance'],
            'incentive' => (float) $account['incentive_balance'],
            'pending' => (float) $account['pending_balance'],
            'approved' => (float) $account['approved_balance'],
            'available' => (float) $account['available_balance'],
            'earned' => (float) $account['total_earned'],
            'withdrawn' => (float) $account['total_withdrawn'],
            'locked' => (float) $account['locked_balance'],
        ];
    }
}
