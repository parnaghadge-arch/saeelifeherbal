<?php
/**
 * Payout lifecycle.
 *
 * Two entry points share one table and one wallet lock:
 *  - manual: member explicitly requests (member/payout-request.php) -> pending
 *  - auto:   earnings that reach the minimum automatically appear as a pending
 *            payout in the admin report (syncAutoPayouts). The member never has
 *            to request. When the admin downloads the report (bank schedule /
 *            CSV / Excel / PDF) every included row moves to 'processing'
 *            ("in progress"); after the admin pays through the bank and marks
 *            it paid, the member sees "Payment completed" with the UTR.
 *
 * Statuses: pending -> (approved) -> processing -> paid. rejected releases the lock.
 * KYC and bank gates, deduction calculation, wallet locking and payout statements
 * are shared by both entry points.
 *
 * Existing installs need the auto-payout columns (source/cycle_key/report_*) and the
 * 'processing' enum value. ensureSchema() adds them automatically on first use, so
 * deploying the code without running "php tools/console.php upgrade" no longer
 * fatals with "Unknown column 'source'" (MySQL 1054). Writes are additionally
 * filtered to existing columns as a fallback if the repair cannot run.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class PayoutService
{
    /** Statuses that keep the wallet amount locked and block a second open payout. */
    public const OPEN_STATUSES = ['pending', 'approved', 'processing'];

    /** Connections that already ran ensureSchema() in this request (weak: no leaks). */
    private static ?\WeakMap $schemaEnsured = null;

    private array $settings;

    public function __construct(private Database $db)
    {
        self::ensureSchema($this->db);
        $this->settings = $db->first('SELECT * FROM payout_settings WHERE id = 1') ?? [
            'min_withdrawal_amount' => 500, 'max_withdrawal_amount' => 100000,
            'withdrawal_fee_type' => 'fixed', 'withdrawal_fee_value' => 0, 'tds_percent' => 0,
            'processing_days' => 3, 'require_kyc' => 1, 'require_verified_bank' => 1,
            'auto_approve' => 0, 'auto_payout_enabled' => 1, 'payouts_enabled' => 1,
        ];
    }

    /**
     * Self-healing schema repair for the auto-payout columns.
     *
     * On an install created before auto payouts existed, payout_requests lacks
     * source/cycle_key/report_downloaded_at/by/format and payout_settings lacks
     * auto_payout_enabled, and the status ENUM lacks 'processing'. Without the
     * repair every payout page dies with 1054 Unknown column. This adds the
     * missing columns (same ALTERs as "php tools/console.php upgrade") and widens
     * the ENUM, once per request. It never throws: if the repair cannot run
     * (no permission, table locked), callers fall back to filtering writes to
     * existing columns via Database::filterColumns().
     */
    public static function ensureSchema(Database $db): void
    {
        // Keyed per connection, not per driver: tools and tests can hold more than
        // one connection (e.g. a scratch database next to the live one), and each
        // of them needs its own repair pass. WeakMap keeps a released connection
        // from lingering, and unlike spl_object_id() an id can never be recycled
        // onto a new connection and skip its repair.
        self::$schemaEnsured ??= new \WeakMap();
        if (isset(self::$schemaEnsured[$db])) {
            return;
        }
        self::$schemaEnsured[$db] = true;
        try {
            $tables = Schema::tables();
            if (isset($tables['payout_requests'])) {
                Schema::ensureColumns($db, 'payout_requests', $tables['payout_requests']);
            }
            if (isset($tables['payout_settings'])) {
                Schema::ensureColumns($db, 'payout_settings', $tables['payout_settings']);
            }
            // ensureColumns only adds columns; widen the status ENUM explicitly.
            if ($db->driver() === 'mysql' && $db->hasColumn('payout_requests', 'status')) {
                try {
                    $db->statement("ALTER TABLE payout_requests MODIFY status ENUM('pending','approved','processing','rejected','paid','cancelled') NOT NULL DEFAULT 'pending'");
                } catch (\Throwable $e) {
                    error_log('payout status enum widen skipped: ' . $e->getMessage());
                }
            }
        } catch (\Throwable $e) {
            error_log('payout schema ensure failed: ' . $e->getMessage());
        }
    }

    public function settings(): array
    {
        return $this->settings;
    }

    public function autoEnabled(): bool
    {
        return (int) ($this->settings['auto_payout_enabled'] ?? 1) === 1
            && (int) ($this->settings['payouts_enabled'] ?? 1) === 1;
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
            $blockers[] = 'KYC must be submitted and approved before payouts are released.';
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
            $blockers[] = 'Available balance is below the minimum payout amount of ' . money((float) $this->settings['min_withdrawal_amount']) . '.';
        }
        $pending = $this->db->first(
            "SELECT id FROM payout_requests WHERE member_id = :m AND status IN ('pending','approved','processing') LIMIT 1",
            ['m' => $memberId]
        );
        if ($pending) {
            $blockers[] = 'You already have a payout in progress.';
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

    // ------------------------------------------------------------ auto payouts

    /**
     * Members whose withdrawable balance reached the minimum and who have no open
     * payout. KYC/bank gates are NOT applied here — payableMembers() applies them,
     * blockedMembers() reports the rest so the admin can see why money is held.
     *
     * @return list<array<string,mixed>>
     */
    public function payableCandidates(): array
    {
        $min = (float) $this->settings['min_withdrawal_amount'];
        return $this->db->select(
            "SELECT m.id AS member_id, m.name, m.member_code, m.mobile, m.status AS member_status,
                    m.kyc_status, w.available_balance
             FROM wallet_accounts w
             JOIN members m ON m.id = w.member_id
             WHERE w.available_balance >= :min
               AND NOT EXISTS (
                   SELECT 1 FROM payout_requests p
                   WHERE p.member_id = m.id AND p.status IN ('pending','approved','processing')
               )
             ORDER BY w.available_balance DESC",
            ['min' => $min]
        );
    }

    /**
     * Eligible members ready for an automatic payout, with deduction preview and bank.
     *
     * @return list<array<string,mixed>>
     */
    public function payableMembers(): array
    {
        $out = [];
        foreach ($this->payableCandidates() as $row) {
            $memberId = (int) $row['member_id'];
            $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
            if (!$member || $member['status'] !== 'active') {
                continue;
            }
            if ((int) $this->settings['require_kyc'] === 1 && $member['kyc_status'] !== 'approved') {
                continue;
            }
            $bank = $this->primaryBank($memberId);
            if ((int) $this->settings['require_verified_bank'] === 1) {
                if (!$bank || $bank['status'] !== 'verified') {
                    continue;
                }
            }
            $gross = $this->autoGross((float) $row['available_balance']);
            if ($gross < (float) $this->settings['min_withdrawal_amount']) {
                continue;
            }
            $row['member'] = $member;
            $row['bank'] = $bank;
            $row['gross'] = $gross;
            $row['calc'] = $this->computeDeductions($gross);
            $out[] = $row;
        }
        return $out;
    }

    /**
     * Members with money available but blocked from auto payout (KYC / bank / status).
     * Shown in the admin report so held money is visible instead of silent.
     *
     * @return list<array<string,mixed>>
     */
    public function blockedMembers(): array
    {
        $out = [];
        foreach ($this->payableCandidates() as $row) {
            $memberId = (int) $row['member_id'];
            $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
            $blockers = [];
            if (!$member) {
                continue;
            }
            if ($member['status'] !== 'active') {
                $blockers[] = 'Account is ' . $member['status'];
            }
            if ((int) $this->settings['require_kyc'] === 1 && $member['kyc_status'] !== 'approved') {
                $blockers[] = 'KYC ' . str_replace('_', ' ', (string) $member['kyc_status']);
            }
            $bank = $this->primaryBank($memberId);
            if ((int) $this->settings['require_verified_bank'] === 1) {
                if (!$bank) {
                    $blockers[] = 'No bank account';
                } elseif ($bank['status'] !== 'verified') {
                    $blockers[] = 'Bank ' . $bank['status'];
                }
            }
            if ($blockers === []) {
                continue; // eligible — payableMembers() covers it
            }
            $row['blockers'] = $blockers;
            $row['bank'] = $bank;
            $out[] = $row;
        }
        return $out;
    }

    /** Gross for an auto payout: full available balance, capped per-request. */
    private function autoGross(float $available): float
    {
        $max = (float) $this->settings['max_withdrawal_amount'];
        $gross = $available;
        if ($max > 0 && $gross > $max) {
            $gross = $max;
        }
        return round($gross, 2);
    }

    /**
     * Create pending auto payouts for every eligible member. Idempotent: members
     * with an open payout are skipped, so calling this on every report/member
     * page load (and from cron) never duplicates a payout.
     */
    public function syncAutoPayouts(): int
    {
        if (!$this->autoEnabled()) {
            return 0;
        }
        $created = 0;
        foreach ($this->payableMembers() as $row) {
            try {
                $this->createAutoPayout((int) $row['member_id'], (float) $row['gross'], $row['bank'] ?? null, $row['member'] ?? null);
                $created++;
            } catch (\Throwable $e) {
                error_log('auto payout failed for member ' . (int) $row['member_id'] . ': ' . $e->getMessage());
            }
        }
        return $created;
    }

    /** Sync a single member (used on member pages so their own payout appears). */
    public function syncAutoPayoutForMember(int $memberId): ?int
    {
        if (!$this->autoEnabled()) {
            return null;
        }
        $eligibility = $this->eligibility($memberId);
        if (!$eligibility['eligible']) {
            return null;
        }
        $gross = $this->autoGross((float) $eligibility['available']);
        if ($gross < (float) $this->settings['min_withdrawal_amount']) {
            return null;
        }
        return $this->createAutoPayout($memberId, $gross, $eligibility['bank'], $eligibility['member']);
    }

    private function createAutoPayout(int $memberId, float $gross, ?array $bank, ?array $member): int
    {
        $calc = $this->computeDeductions($gross);
        $member = $member ?? $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        $id = $this->db->transaction(function (Database $db) use ($memberId, $gross, $calc, $bank, $member) {
            // Filter to existing columns: on an install where the repair above could
            // not add source/cycle_key, the payout is still created (as a plain
            // pending row) instead of dying with 1054 Unknown column.
            $requestId = $db->insert('payout_requests', $db->filterColumns('payout_requests', [
                'request_code' => Sequence::code('PAY', 'payout'),
                'member_id' => $memberId,
                'amount' => $gross,
                'deduction_amount' => $calc['deduction'],
                'tds_amount' => $calc['tds'],
                'admin_charges' => $calc['admin_charges'],
                'net_amount' => $calc['net'],
                'status' => 'pending',
                'source' => 'auto',
                'cycle_key' => date('Y-m'),
                'bank_account_id' => $bank['id'] ?? null,
                'bank_snapshot' => $bank ? json_encode([
                    'account_holder_name' => $bank['account_holder_name'],
                    'bank_name' => $bank['bank_name'],
                    'account_number' => $bank['account_number'],
                    'ifsc_code' => $bank['ifsc_code'],
                    'branch_name' => $bank['branch_name'],
                ]) : null,
                'kyc_snapshot' => json_encode(['status' => $member['kyc_status'] ?? 'approved']),
                'member_note' => 'Auto payout — earnings reached the minimum payout amount.',
                'requested_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]));
            (new WalletService($db))->recalculate($memberId);
            return $requestId;
        });
        Notification::send('member', $memberId, 'payout_requested', 'Auto Payout Added To Report',
            'Your earnings of ' . money($gross) . ' have been added to the payout report automatically. No action is needed — payment will be processed to your bank account.',
            ['request_code' => 'PAY-' . $id, 'amount' => money($gross)],
            ['link' => 'payouts', 'type' => 'info', 'icon' => 'fa-money-bill-transfer']);
        Audit::log('payout_auto_created', 'payouts', 'Auto payout created for member ' . ($member['member_code'] ?? $memberId) . ' ' . money($gross), 'payout_request', $id);
        return $id;
    }

    /**
     * The admin downloaded a payout report: every included payable row moves to
     * 'processing' ("in progress") and the member is notified. Paid/rejected rows
     * are never touched, so a report can be downloaded any number of times.
     *
     * @param list<int> $ids
     */
    public function markReportDownloaded(array $ids, string $format): int
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids), static fn (int $i): bool => $i > 0)));
        if ($ids === []) {
            return 0;
        }
        $moved = 0;
        foreach ($ids as $id) {
            $request = $this->db->first('SELECT * FROM payout_requests WHERE id = :id', ['id' => $id]);
            if (!$request || !in_array($request['status'], ['pending', 'approved'], true)) {
                continue;
            }
            $this->db->transaction(function (Database $db) use ($id, $format) {
                // report_* columns only exist after the schema repair; filter so a
                // not-yet-upgraded install still moves to 'processing' (the status
                // itself is the important part) instead of throwing 1054.
                $db->update('payout_requests', $db->filterColumns('payout_requests', [
                    'status' => 'processing',
                    'report_downloaded_at' => date('Y-m-d H:i:s'),
                    'report_downloaded_by' => AdminAuth::id(),
                    'report_format' => $format !== '' ? $format : null,
                    'updated_at' => date('Y-m-d H:i:s'),
                ]), 'id = :id', ['id' => $id]);
            });
            $moved++;
            Notification::send('member', (int) $request['member_id'], 'payout_in_progress', 'Payout In Progress',
                'Your payout ' . $request['request_code'] . ' of ' . money((float) $request['net_amount']) . ' is now in progress. The accounts team has downloaded the payout report and payment is being processed to your bank account.',
                ['request_code' => $request['request_code'], 'amount' => money((float) $request['net_amount'])],
                ['link' => 'payouts', 'type' => 'info', 'icon' => 'fa-hourglass-half']);
            Audit::log('payout_in_progress', 'payouts', 'Payout ' . $request['request_code'] . ' moved to in progress after report download (' . $format . ')', 'payout_request', $id);
        }
        return $moved;
    }

    /** Member-facing summary for the payout statement page. */
    public function memberSummary(int $memberId): array
    {
        $wallet = (new WalletService($this->db))->account($memberId);
        $open = $this->db->first(
            "SELECT * FROM payout_requests WHERE member_id = :m AND status IN ('pending','approved','processing') ORDER BY id DESC LIMIT 1",
            ['m' => $memberId]
        );
        $totals = $this->db->first(
            "SELECT COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END),0) AS paid_net,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN amount ELSE 0 END),0) AS paid_gross,
                    COALESCE(SUM(CASE WHEN status IN ('pending','approved','processing') THEN net_amount ELSE 0 END),0) AS open_net,
                    COUNT(*) AS total_requests,
                    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid_count
             FROM payout_requests WHERE member_id = :m",
            ['m' => $memberId]
        ) ?? [];
        $available = (float) ($wallet['available_balance'] ?? 0);
        $preview = null;
        if (!$open && $available >= (float) $this->settings['min_withdrawal_amount']) {
            $gross = $this->autoGross($available);
            $preview = $this->computeDeductions($gross);
        }
        return [
            'wallet' => $wallet,
            'available' => $available,
            'open' => $open,
            'preview' => $preview,
            'paid_net' => (float) ($totals['paid_net'] ?? 0),
            'paid_gross' => (float) ($totals['paid_gross'] ?? 0),
            'open_net' => (float) ($totals['open_net'] ?? 0),
            'total_requests' => (int) ($totals['total_requests'] ?? 0),
            'paid_count' => (int) ($totals['paid_count'] ?? 0),
            'min_amount' => (float) $this->settings['min_withdrawal_amount'],
            'settings' => $this->settings,
        ];
    }

    // ------------------------------------------------------------ manual request

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
            $requestId = $db->insert('payout_requests', $db->filterColumns('payout_requests', [
                'request_code' => Sequence::code('PAY', 'payout'),
                'member_id' => $memberId,
                'amount' => $amount,
                'deduction_amount' => $calc['deduction'],
                'tds_amount' => $calc['tds'],
                'admin_charges' => $calc['admin_charges'],
                'net_amount' => $calc['net'],
                'status' => 'pending',
                'source' => 'manual',
                'cycle_key' => null,
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
            ]));
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
        $hardBlockers = array_values(array_filter($eligibility['blockers'], static fn ($b) => !str_contains($b, 'payout in progress') && !str_contains($b, 'payout request in progress')));
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
        if (!$request || !in_array($request['status'], ['pending', 'approved', 'processing'], true)) {
            return ['ok' => false, 'message' => 'Only pending, approved or in-progress requests can be rejected.'];
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
        if (!$request || !in_array($request['status'], ['approved', 'pending', 'processing'], true)) {
            return ['ok' => false, 'message' => 'Only pending, approved or in-progress requests can be marked as paid.'];
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
        Notification::send('member', (int) $req['member_id'], 'payout_completed', 'Payment Completed',
            'Your payout ' . $req['request_code'] . ' of ' . money((float) $req['net_amount']) . ' has been paid. UTR: ' . $utr,
            ['request_code' => $req['request_code'], 'amount' => money((float) $req['net_amount']), 'utr' => $utr],
            ['link' => 'payouts', 'type' => 'success', 'icon' => 'fa-check-double']);
        Audit::log('payout_paid', 'payouts', 'Payout paid ' . $req['request_code'] . ' UTR ' . $utr, 'payout_request', $requestId);
        return ['ok' => true, 'message' => 'Payout marked as paid. The member can now see Payment Completed with the UTR.'];
    }

    /** Dashboard counters for the admin payout screen. */
    public function stats(): array
    {
        // The auto_count term needs the source column. On a not-yet-upgraded install
        // (repair could not add it) fall back to a query without it instead of
        // throwing 1054 — the page must still render.
        $hasSource = $this->db->hasColumn('payout_requests', 'source');
        $autoExpr = $hasSource
            ? ", SUM(CASE WHEN source = 'auto' THEN 1 ELSE 0 END) AS auto_count"
            : ", 0 AS auto_count";
        try {
            $row = $this->db->first(
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending,
                        SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) AS approved,
                        SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) AS processing,
                        SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) AS rejected,
                        SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
                        COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END),0) AS paid_amount,
                        COALESCE(SUM(CASE WHEN status IN ('pending','approved','processing') THEN net_amount ELSE 0 END),0) AS pending_amount,
                        COALESCE(SUM(CASE WHEN status = 'processing' THEN net_amount ELSE 0 END),0) AS processing_amount"
                        . $autoExpr . "
                 FROM payout_requests"
            ) ?? [];
        } catch (\Throwable $e) {
            error_log('payout stats failed: ' . $e->getMessage());
            $row = [];
        }
        return [
            'total' => (int) ($row['total'] ?? 0),
            'pending' => (int) ($row['pending'] ?? 0),
            'approved' => (int) ($row['approved'] ?? 0),
            'processing' => (int) ($row['processing'] ?? 0),
            'rejected' => (int) ($row['rejected'] ?? 0),
            'paid' => (int) ($row['paid'] ?? 0),
            'paid_amount' => (float) ($row['paid_amount'] ?? 0),
            'pending_amount' => (float) ($row['pending_amount'] ?? 0),
            'processing_amount' => (float) ($row['processing_amount'] ?? 0),
            'auto_count' => (int) ($row['auto_count'] ?? 0),
            'requested_amount' => (float) ($this->db->value('SELECT COALESCE(SUM(amount),0) FROM payout_requests') ?? 0),
        ];
    }
}
