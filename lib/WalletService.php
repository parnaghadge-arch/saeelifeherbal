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
            // so only those have to be subtracted from the balance.
            $lockedPending = self::fromPaise(self::toPaise((float) $db->value(
                "SELECT COALESCE(SUM(net_amount),0) FROM payout_requests WHERE member_id = :m AND status IN ('pending','approved')",
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
