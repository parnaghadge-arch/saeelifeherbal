<?php
/**
 * Payout lifecycle: request -> approve/reject -> paid, with KYC and bank eligibility gates,
 * deduction calculation, wallet locking and payout statements.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class PayoutService
{
    private array $settings;

    public function __construct(private Database $db)
    {
        $this->settings = $db->first('SELECT * FROM payout_settings WHERE id = 1') ?? [
            'min_withdrawal_amount' => 500, 'max_withdrawal_amount' => 100000,
            'withdrawal_fee_type' => 'fixed', 'withdrawal_fee_value' => 0, 'tds_percent' => 0,
            'processing_days' => 3, 'require_kyc' => 1, 'require_verified_bank' => 1,
            'auto_approve' => 0, 'payouts_enabled' => 1,
        ];
    }

    public function settings(): array
    {
        return $this->settings;
    }

    /** Eligibility of a member for payouts, with human readable blockers. */
    public function eligibility(int $memberId): array
    {
        $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        $wallet = (new WalletService($this->db))->account($memberId);
        $bank = $this->primaryBank($memberId);
        $blockers = [];

        if (!$member) {
            return ['eligible' => false, 'blockers' => ['Member not found.'], 'available' => 0.0, 'bank' => null, 'member' => null];
        }
        if ((int) $this->settings['payouts_enabled'] !== 1) {
            $blockers[] = 'Payouts are currently disabled by the administrator.';
        }
        if ((int) $this->settings['require_kyc'] === 1 && $member['kyc_status'] !== 'approved') {
            $blockers[] = 'KYC must be submitted and approved before requesting a payout.';
        }
        if ((int) $this->settings['require_verified_bank'] === 1) {
            if (!$bank) {
                $blockers[] = 'Add your bank account details to receive payouts.';
            } elseif ($bank['status'] !== 'verified') {
                $blockers[] = 'Your bank account is pending verification.';
            }
        }
        if ($member['status'] !== 'active') {
            $blockers[] = 'Your account must be active.';
        }
        $available = (float) ($wallet['available_balance'] ?? 0);
        if ($available < (float) $this->settings['min_withdrawal_amount']) {
            $blockers[] = 'Available balance is below the minimum withdrawal amount of ' . money((float) $this->settings['min_withdrawal_amount']) . '.';
        }
        $pending = $this->db->first(
            "SELECT id FROM payout_requests WHERE member_id = :m AND status IN ('pending','approved') LIMIT 1",
            ['m' => $memberId]
        );
        if ($pending) {
            $blockers[] = 'You already have a payout request in progress.';
        }

        return [
            'eligible' => $blockers === [],
            'blockers' => $blockers,
            'available' => $available,
            'bank' => $bank,
            'member' => $member,
        ];
    }

    public function primaryBank(int $memberId): ?array
    {
        $bank = $this->db->first(
            'SELECT * FROM member_bank_accounts WHERE member_id = :m ORDER BY is_primary DESC, id DESC LIMIT 1',
            ['m' => $memberId]
        );
        return $bank ?: null;
    }

    /** Calculate deductions for a requested gross amount. */
    public function computeDeductions(float $gross): array
    {
        $fee = 0.0;
        if ((float) $this->settings['withdrawal_fee_value'] > 0) {
            $fee = $this->settings['withdrawal_fee_type'] === 'percent'
                ? MlmCommissionEngine::compute($gross, 'percent', (float) $this->settings['withdrawal_fee_value'])
                : (float) $this->settings['withdrawal_fee_value'];
        }
        $tds = (float) $this->settings['tds_percent'] > 0
            ? MlmCommissionEngine::compute($gross, 'percent', (float) $this->settings['tds_percent'])
            : 0.0;
        $fee = min($fee, $gross);
        $net = round($gross - $fee - $tds, 2);
        return ['gross' => $gross, 'admin_charges' => $fee, 'tds' => $tds, 'deduction' => round($fee + $tds, 2), 'net' => max(0, $net)];
    }

    /** Member faced request creation. */
    public function request(int $memberId, float $amount, ?string $note = null): array
    {
        $eligibility = $this->eligibility($memberId);
        if (!$eligibility['eligible']) {
            return ['ok' => false, 'message' => implode(' ', $eligibility['blockers'])];
        }
        if ($amount < (float) $this->settings['min_withdrawal_amount']) {
            return ['ok' => false, 'message' => 'Minimum withdrawal amount is ' . money((float) $this->settings['min_withdrawal_amount']) . '.'];
        }
        if ($amount > (float) $this->settings['max_withdrawal_amount']) {
            return ['ok' => false, 'message' => 'Maximum withdrawal amount per request is ' . money((float) $this->settings['max_withdrawal_amount']) . '.'];
        }
        if (MlmCommissionEngine::toPaise($amount) > MlmCommissionEngine::toPaise($eligibility['available'])) {
            return ['ok' => false, 'message' => 'Requested amount exceeds your available balance of ' . money($eligibility['available']) . '.'];
        }

        $calc = $this->computeDeductions($amount);
        $bank = $eligibility['bank'];
        $member = $eligibility['member'];

        $id = $this->db->transaction(function (Database $db) use ($memberId, $amount, $calc, $bank, $member, $note) {
            $requestId = $db->insert('payout_requests', [
                'request_code' => Sequence::code('PAY', 'payout'),
                'member_id' => $memberId,
                'amount' => $amount,
                'deduction_amount' => $calc['deduction'],
                'tds_amount' => $calc['tds'],
                'admin_charges' => $calc['admin_charges'],
                'net_amount' => $calc['net'],
                'status' => 'pending',
                'bank_account_id' => $bank['id'] ?? null,
                'bank_snapshot' => $bank ? json_encode([
                    'account_holder_name' => $bank['account_holder_name'],
                    'bank_name' => $bank['bank_name'],
                    'account_number' => $bank['account_number'],
                    'ifsc_code' => $bank['ifsc_code'],
                    'branch_name' => $bank['branch_name'],
                ]) : null,
                'kyc_snapshot' => json_encode(['status' => $member['kyc_status']]),
                'member_note' => $note,
                'requested_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
            // Lock the amount from the available balance while the request is processed.
            $wallet = new WalletService($db);
            $wallet->recalculate($memberId);
            return $requestId;
        });

        Notification::send('member', $memberId, 'payout_requested', 'Payout Request Received',
            'Your payout request for ' . money($amount) . ' has been received and is pending review.',
            ['request_code' => 'PAY-' . $id, 'amount' => money($amount)],
            ['link' => 'payouts', 'type' => 'info', 'icon' => 'fa-money-bill-transfer']);
        Notification::sendToAdmins('New payout request', $member['name'] . ' (' . $member['member_code'] . ') requested ' . money($amount) . '.', ['template' => 'payout_requested', 'link' => 'payouts']);

        Audit::log('payout_requested', 'payouts', 'Member requested payout ' . money($amount), 'payout_request', $id);
        return ['ok' => true, 'message' => 'Payout request submitted successfully.', 'id' => $id, 'calc' => $calc];
    }

    public function approve(int $requestId, ?string $note = null): array
    {
        $request = $this->db->first('SELECT * FROM payout_requests WHERE id = :id', ['id' => $requestId]);
        if (!$request || $request['status'] !== 'pending') {
            return ['ok' => false, 'message' => 'Only pending requests can be approved.'];
        }
        $eligibility = $this->eligibility((int) $request['member_id']);
        $hardBlockers = array_values(array_filter($eligibility['blockers'], static fn ($b) => !str_contains($b, 'payout request in progress')));
        if ($hardBlockers !== []) {
            return ['ok' => false, 'message' => 'Member is not payout eligible: ' . implode(' ', $hardBlockers)];
        }
        $this->db->transaction(function (Database $db) use ($requestId, $note) {
            $db->update('payout_requests', [
                'status' => 'approved',
                'admin_note' => $note,
                'approved_by' => AdminAuth::id(),
                'approved_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $requestId]);
        });
        $req = $this->db->first('SELECT * FROM payout_requests WHERE id = :id', ['id' => $requestId]);
        Notification::send('member', (int) $req['member_id'], 'payout_approved', 'Payout Approved',
            'Your payout request ' . $req['request_code'] . ' for ' . money((float) $req['net_amount']) . ' has been approved and will be processed shortly.',
            ['request_code' => $req['request_code']],
            ['link' => 'payouts', 'type' => 'success', 'icon' => 'fa-circle-check']);
        Audit::log('payout_approved', 'payouts', 'Payout approved ' . $req['request_code'], 'payout_request', $requestId);
        return ['ok' => true, 'message' => 'Payout approved.'];
    }

    public function reject(int $requestId, string $reason): array
    {
        $request = $this->db->first('SELECT * FROM payout_requests WHERE id = :id', ['id' => $requestId]);
        if (!$request || !in_array($request['status'], ['pending', 'approved'], true)) {
            return ['ok' => false, 'message' => 'Only pending or approved requests can be rejected.'];
        }
        if (trim($reason) === '') {
            return ['ok' => false, 'message' => 'A rejection reason is required.'];
        }
        $this->db->transaction(function (Database $db) use ($requestId, $reason) {
            $db->update('payout_requests', [
                'status' => 'rejected',
                'rejection_reason' => $reason,
                'rejected_by' => AdminAuth::id(),
                'rejected_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $requestId]);
        });
        // Releasing the lock simply means recalculating the available balance.
        (new WalletService($this->db))->recalculate((int) $request['member_id']);

        Notification::send('member', (int) $request['member_id'], 'payout_rejected', 'Payout Rejected',
            'Your payout request ' . $request['request_code'] . ' was rejected. Reason: ' . $reason,
            ['request_code' => $request['request_code'], 'reason' => $reason],
            ['link' => 'payouts', 'type' => 'danger', 'icon' => 'fa-circle-xmark']);
        Audit::log('payout_rejected', 'payouts', 'Payout rejected: ' . $reason, 'payout_request', $requestId);
        return ['ok' => true, 'message' => 'Payout rejected and balance released.'];
    }

    public function markPaid(int $requestId, string $utr, ?string $proofPath = null, string $mode = 'bank_transfer'): array
    {
        $request = $this->db->first('SELECT * FROM payout_requests WHERE id = :id', ['id' => $requestId]);
        if (!$request || !in_array($request['status'], ['approved', 'pending'], true)) {
            return ['ok' => false, 'message' => 'Only approved requests can be marked as paid.'];
        }
        if (trim($utr) === '') {
            return ['ok' => false, 'message' => 'A UTR / transaction reference is required.'];
        }
        $this->db->transaction(function (Database $db) use ($request, $requestId, $utr, $proofPath, $mode) {
            $memberId = (int) $request['member_id'];
            $net = (float) $request['net_amount'];
            $db->update('payout_requests', [
                'status' => 'paid',
                'utr_number' => $utr,
                'payment_proof' => $proofPath,
                'payment_mode' => $mode,
                'paid_by' => AdminAuth::id(),
                'paid_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ], 'id = :id', ['id' => $requestId]);

            $db->insert('payout_transactions', [
                'payout_request_id' => $requestId,
                'member_id' => $memberId,
                'amount' => $net,
                'mode' => $mode,
                'reference_number' => $utr,
                'proof_file' => $proofPath,
                'status' => 'success',
                'remarks' => 'Payout marked paid',
                'processed_by' => AdminAuth::id(),
                'processed_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            // Record the withdrawal in the ledger (debit against the earned categories).
            $wallet = new WalletService($db);
            $account = $wallet->account($memberId);
            $remaining = MlmCommissionEngine::toPaise($net);
            $order = ['direct', 'grand_direct', 'royalty', 'incentive'];
            foreach ($order as $type) {
                if ($remaining <= 0) {
                    break;
                }
                $balancePaise = MlmCommissionEngine::toPaise((float) $account[$type . '_balance']);
                if ($balancePaise <= 0) {
                    continue;
                }
                $take = min($balancePaise, $remaining);
                $wallet->post($memberId, $type, $take / 100, [
                    'direction' => 'debit',
                    'reference_type' => 'payout',
                    'reference_id' => $requestId,
                    'status' => 'paid',
                    'description' => 'Payout ' . $request['request_code'] . ' paid (UTR ' . $utr . ')',
                ]);
                $remaining -= $take;
                $account = $wallet->account($memberId);
            }
            $wallet->recalculate($memberId);
        });

        $req = $this->db->first('SELECT * FROM payout_requests WHERE id = :id', ['id' => $requestId]);
        Notification::send('member', (int) $req['member_id'], 'payout_completed', 'Payout Completed',
            'Your payout ' . $req['request_code'] . ' of ' . money((float) $req['net_amount']) . ' has been paid. UTR: ' . $utr,
            ['request_code' => $req['request_code'], 'amount' => money((float) $req['net_amount']), 'utr' => $utr],
            ['link' => 'payouts', 'type' => 'success', 'icon' => 'fa-check-double']);
        Audit::log('payout_paid', 'payouts', 'Payout paid ' . $req['request_code'] . ' UTR ' . $utr, 'payout_request', $requestId);
        return ['ok' => true, 'message' => 'Payout marked as paid.'];
    }

    /** Dashboard counters for the admin payout screen. */
    public function stats(): array
    {
        $row = $this->db->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                    SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                    SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END),0) AS paid_amount,
                    COALESCE(SUM(CASE WHEN status IN ('pending','approved') THEN net_amount ELSE 0 END),0) AS pending_amount
             FROM payout_requests"
        ) ?? [];
        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'paid' => (int) ($row['paid'] ?? 0),
            'paid_amount' => (float) ($row['paid_amount'] ?? 0),
            'pending_amount' => (float) ($row['pending_amount'] ?? 0),
            'requested_amount' => (float) ($this->db->value('SELECT COALESCE(SUM(amount),0) FROM payout_requests') ?? 0),
        ];
    }
}
