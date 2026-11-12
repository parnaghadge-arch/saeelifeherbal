<?php
/**
 * Royalty / performance incentive engine.
 *
 * A member qualifies for a royalty rule when their achievement snapshot satisfies every
 * configured condition. Achievements are unique per (member, rule, cycle) which prevents
 * duplicate rewards for the same milestone. Recurring rules use a monthly cycle key.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class RoyaltyEngine
{
    public function __construct(private Database $db)
    {
    }

    /** Evaluate a single member against all active rules. */
    public function evaluateMember(int $memberId): array
    {
        $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        if (!$member || (int) $member['is_company'] === 1) {
            return ['achieved' => 0, 'details' => []];
        }
        $rules = $this->db->select(
            "SELECT * FROM royalty_rules WHERE status = 1
             AND (start_date IS NULL OR start_date <= :today)
             AND (end_date IS NULL OR end_date >= :today)
             ORDER BY level_no ASC, sort_order ASC",
            ['today' => date('Y-m-d')]
        );
        if ($rules === []) {
            return ['achieved' => 0, 'details' => []];
        }

        $team = new TeamService($this->db);
        $direct = $team->directStats($memberId);
        $downline = $team->downlineCount($memberId);
        $teamSales = $team->teamSales($memberId);
        $today = date('Y-m-d');

        $achieved = 0;
        $details = [];
        foreach ($rules as $rule) {
            $cycleKey = $rule['frequency'] === 'recurring' ? date('Y-m') : 'once';
            $snapshot = [
                'direct_referrals_snapshot' => $direct['total'],
                'downline_snapshot' => $downline['total'],
                'active_snapshot' => $downline['active'],
                'qualifying_sales_snapshot' => $teamSales['amount'],
            ];

            $qualified = $direct['total'] >= (int) $rule['required_direct_referrals']
                && $downline['total'] >= (int) $rule['required_downline']
                && $downline['active'] >= (int) $rule['required_active_members']
                && $teamSales['amount'] >= (float) $rule['min_qualifying_sales'];

            if ((int) $rule['required_purchase_count'] > 0) {
                $purchases = (int) $this->db->value(
                    "SELECT COUNT(*) FROM orders WHERE member_id = :m AND payment_status = 'paid'",
                    ['m' => $memberId]
                );
                $qualified = $qualified && $purchases >= (int) $rule['required_purchase_count'];
            }

            if (!$qualified) {
                continue;
            }

            $exists = $this->db->first(
                'SELECT id FROM royalty_achievements WHERE member_id = :m AND royalty_rule_id = :r AND cycle_key = :c',
                ['m' => $memberId, 'r' => $rule['id'], 'c' => $cycleKey]
            );
            if ($exists) {
                continue;
            }

            $reward = $rule['reward_type'] === 'percent'
                ? MlmCommissionEngine::compute($teamSales['amount'], 'percent', (float) $rule['reward_percent'])
                : (float) $rule['reward_amount'];
            if ($reward <= 0) {
                continue;
            }

            $requiresApproval = (int) $rule['requires_admin_approval'] === 1 || (int) MlmSettings::get('royalty_requires_approval', 1) === 1;

            $achievementId = $this->db->insert('royalty_achievements', array_merge($snapshot, [
                'member_id' => $memberId,
                'royalty_rule_id' => (int) $rule['id'],
                'cycle_key' => $cycleKey,
                'reward_amount' => $reward,
                'status' => $requiresApproval ? 'pending' : 'approved',
                'achieved_at' => date('Y-m-d H:i:s', strtotime($today)),
                'remarks' => sprintf('%s achieved (team %d, direct %d, team sales %s)', $rule['name'], $downline['total'], $direct['total'], money($teamSales['amount'])),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]));

            $achieved++;
            $details[] = ['rule' => $rule['name'], 'reward' => $reward, 'achievement_id' => $achievementId];

            Notification::send(
                'member',
                $memberId,
                'royalty_achieved',
                'Royalty Milestone Achieved',
                'Congratulations! You qualified for ' . $rule['name'] . ' and earned ' . money($reward) . '.',
                ['royalty_name' => $rule['name'], 'amount' => money($reward)],
                ['link' => 'royalty', 'type' => 'success', 'icon' => 'fa-trophy']
            );

            if (!$requiresApproval) {
                $this->approveAchievement($achievementId, 'auto');
            }
        }

        return ['achieved' => $achieved, 'details' => $details];
    }

    /** Approve an achievement and credit the royalty wallet. */
    public function approveAchievement(int $achievementId, string $actor = 'admin'): bool
    {
        return $this->db->transaction(function (Database $db) use ($achievementId, $actor) {
            $row = $db->first('SELECT * FROM royalty_achievements WHERE id = :id', ['id' => $achievementId]);
            if (!$row || $row['status'] !== 'pending') {
                return false;
            }
            $rule = $db->first('SELECT * FROM royalty_rules WHERE id = :id', ['id' => $row['royalty_rule_id']]);
            if (!$rule) {
                return false;
            }
            $memberId = (int) $row['member_id'];
            $amount = (float) $row['reward_amount'];
            $idempotency = 'royalty|' . $achievementId;

            $existing = $db->first('SELECT id FROM commission_transactions WHERE idempotency_key = :k', ['k' => $idempotency]);
            if ($existing) {
                return false;
            }

            $txId = $db->insert('commission_transactions', [
                'transaction_code' => Sequence::code('CTX', 'commission'),
                'member_id' => $memberId,
                'beneficiary_type' => 'direct',
                'commission_type' => 'royalty',
                'order_id' => null,
                'product_id' => null,
                'product_name' => 'Royalty: ' . $rule['name'],
                'level' => (int) $rule['level_no'],
                'basis_amount' => (float) $row['qualifying_sales_snapshot'],
                'commission_type_rate' => $rule['reward_type'],
                'rate_value' => $rule['reward_type'] === 'percent' ? (float) $rule['reward_percent'] : (float) $rule['reward_amount'],
                'amount' => $amount,
                'status' => 'available',
                'idempotency_key' => $idempotency,
                'remarks' => 'Royalty achievement #' . $achievementId . ' (' . $actor . ')',
                'available_at' => date('Y-m-d H:i:s'),
                'credited_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);

            $db->update('royalty_achievements', [
                'status' => 'approved',
                'commission_transaction_id' => $txId,
                'approved_by' => AdminAuth::id(),
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $achievementId]);

            $wallet = new WalletService($db);
            $wallet->post($memberId, 'royalty', $amount, [
                'reference_type' => 'royalty',
                'reference_id' => $achievementId,
                'status' => 'available',
                'description' => 'Royalty: ' . $rule['name'],
            ]);
            $wallet->recalculate($memberId);
            return true;
        });
    }

    public function rejectAchievement(int $achievementId, string $reason): bool
    {
        return $this->db->transaction(function (Database $db) use ($achievementId, $reason) {
            $row = $db->first('SELECT * FROM royalty_achievements WHERE id = :id', ['id' => $achievementId]);
            if (!$row || $row['status'] !== 'pending') {
                return false;
            }
            $db->update('royalty_achievements', [
                'status' => 'rejected',
                'remarks' => mb_substr($reason, 0, 500),
                'approved_by' => AdminAuth::id(),
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $achievementId]);
            return true;
        });
    }

    /** Evaluate every active member (cron / admin trigger). */
    public function processAll(): int
    {
        $members = $this->db->column("SELECT id FROM members WHERE status = 'active' AND is_company = 0");
        $count = 0;
        foreach ($members as $id) {
            $res = $this->evaluateMember((int) $id);
            if ($res['achieved'] > 0) {
                $count++;
            }
        }
        Audit::log('royalty_processed', 'royalty', sprintf('Royalty evaluation completed for %d members, %d new achievements', count($members), $count));
        return $count;
    }

    /** Current progress towards the next unearned milestone (member dashboard widget). */
    public function progress(int $memberId): array
    {
        $team = new TeamService($this->db);
        $downline = $team->downlineCount($memberId);
        $rules = $this->db->select('SELECT * FROM royalty_rules WHERE status = 1 ORDER BY level_no ASC');
        $earned = $this->db->column('SELECT royalty_rule_id FROM royalty_achievements WHERE member_id = :m', ['m' => $memberId]);
        $earned = array_map('intval', $earned);
        $out = [];
        foreach ($rules as $rule) {
            $out[] = [
                'id' => (int) $rule['id'],
                'name' => $rule['name'],
                'level_no' => (int) $rule['level_no'],
                'required_downline' => (int) $rule['required_downline'],
                'required_direct' => (int) $rule['required_direct_referrals'],
                'reward' => $rule['reward_type'] === 'percent' ? $rule['reward_percent'] . '%' : money((float) $rule['reward_amount']),
                'achieved' => in_array((int) $rule['id'], $earned, true),
                'current_team' => $downline['total'],
                'progress' => $rule['required_downline'] > 0
                    ? min(100, (int) round(($downline['total'] / (int) $rule['required_downline']) * 100))
                    : 100,
            ];
        }
        return $out;
    }
}
