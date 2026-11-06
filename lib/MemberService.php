<?php
/**
 * Member registration, sponsor resolution and pipeline statistics.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class MemberService
{
    public function __construct(private Database $db)
    {
    }

    /**
     * Resolve a sponsor by member code, mobile, email or referral code.
     * Returns null when not found so callers can fall back to the company sponsor.
     */
    public function resolveSponsor(?string $identifier): ?array
    {
        $identifier = trim((string) $identifier);
        if ($identifier === '') {
            return null;
        }
        return $this->db->first(
            'SELECT id, member_code, name, status, is_company FROM members
             WHERE UPPER(member_code) = :c OR referral_code = :c OR mobile = :c OR LOWER(email) = :e
             LIMIT 1',
            ['c' => strtoupper($identifier), 'e' => strtolower($identifier)]
        );
    }

    public function companySponsor(): ?array
    {
        $row = $this->db->first('SELECT * FROM members WHERE is_company = 1 LIMIT 1');
        if ($row) {
            return $row;
        }
        return $this->db->first('SELECT * FROM members WHERE member_code = :c', ['c' => MlmSettings::companySponsorCode()]);
    }

    /** Public sponsor lookup for the registration form (real-time AJAX validation). */
    public function lookupSponsor(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            $company = $this->companySponsor();
            return ['ok' => true, 'company' => true, 'member_code' => $company['member_code'] ?? MlmSettings::companySponsorCode(), 'name' => $company['name'] ?? 'Saee Life Herbal (Company)'];
        }
        $member = $this->resolveSponsor($code);
        if (!$member) {
            return ['ok' => false, 'message' => 'Sponsor ID not found. Please check the ID or leave the field blank to join under the company.'];
        }
        if ($member['status'] === 'suspended') {
            return ['ok' => false, 'message' => 'This sponsor account is inactive. Please contact support.'];
        }
        return [
            'ok' => true,
            'company' => (int) $member['is_company'] === 1,
            'member_code' => $member['member_code'],
            'name' => $member['name'],
        ];
    }

    /**
     * Register a new member.
     *
     * @param array $input  validated input
     * @param array $meta   ['ip' => string, 'source' => string]
     * @return array{ok:bool, message:string, member?:array, errors?:array}
     */
    public function register(array $input, array $meta = []): array
    {
        if ((int) MlmSettings::get('registration_enabled', 1) !== 1) {
            return ['ok' => false, 'message' => 'Registration is temporarily disabled. Please contact support.'];
        }
        $mobile = preg_replace('/\D/', '', (string) ($input['mobile'] ?? ''));
        $mobile = strlen($mobile) > 10 ? substr($mobile, -10) : $mobile;
        $email = strtolower(trim((string) ($input['email'] ?? '')));

        if ($this->db->exists('members', 'mobile = :m', ['m' => $mobile])) {
            return ['ok' => false, 'message' => 'This mobile number is already registered. Please sign in instead.', 'errors' => ['mobile' => ['Already registered.']]];
        }
        if ($email !== '' && $this->db->exists('members', 'LOWER(email) = :e', ['e' => $email])) {
            return ['ok' => false, 'message' => 'This email address is already registered.', 'errors' => ['email' => ['Already registered.']]];
        }

        $sponsorInput = trim((string) ($input['sponsor_code'] ?? ''));
        $sponsor = $this->resolveSponsor($sponsorInput);
        if ($sponsorInput !== '' && !$sponsor) {
            return ['ok' => false, 'message' => 'The sponsor ID you entered does not exist.', 'errors' => ['sponsor_code' => ['Invalid sponsor ID.']]];
        }
        if (!$sponsor) {
            $sponsor = $this->companySponsor();
        }
        if (!$sponsor) {
            return ['ok' => false, 'message' => 'Company sponsor is not configured. Please contact the administrator.'];
        }
        if (isset($input['member_id']) && (int) $input['member_id'] === (int) $sponsor['id']) {
            return ['ok' => false, 'message' => 'A member cannot sponsor themselves.', 'errors' => ['sponsor_code' => ['Invalid sponsor.']]];
        }

        $maxDirect = (int) MlmSettings::get('max_direct_referrals', 0);
        if ($maxDirect > 0) {
            $directCount = (int) $this->db->value('SELECT COUNT(*) FROM members WHERE sponsor_id = :s', ['s' => $sponsor['id']]);
            if ($directCount >= $maxDirect) {
                return ['ok' => false, 'message' => 'This sponsor has reached the maximum number of direct referrals.'];
            }
        }

        try {
            $memberId = $this->db->transaction(function (Database $db) use ($input, $mobile, $email, $sponsor, $meta) {
                $code = Sequence::memberCode();
                $referralCode = self::generateReferralCode($code);
                $now = date('Y-m-d H:i:s');

                $memberId = $db->insert('members', [
                    'member_code' => $code,
                    'sponsor_id' => (int) $sponsor['id'],
                    'original_sponsor_id' => (int) $sponsor['id'],
                    'name' => trim((string) $input['name']),
                    'email' => $email !== '' ? $email : null,
                    'mobile' => $mobile,
                    'password_hash' => Security::hashPassword((string) $input['password']),
                    'role' => in_array($input['role'] ?? 'farmer', ['farmer', 'distributor', 'retailer'], true) ? $input['role'] : 'farmer',
                    'status' => 'active',
                    'is_company' => 0,
                    'referral_code' => $referralCode,
                    'kyc_status' => 'not_submitted',
                    'is_payout_eligible' => 0,
                    'joined_at' => $now,
                    'activated_at' => $now,
                    'terms_accepted_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $db->insert('member_profiles', [
                    'member_id' => $memberId,
                    'date_of_birth' => !empty($input['date_of_birth']) ? $input['date_of_birth'] : null,
                    'gender' => in_array($input['gender'] ?? '', ['male', 'female', 'other'], true) ? $input['gender'] : null,
                    'address' => $input['address'] ?? null,
                    'state' => $input['state'] ?? null,
                    'district' => $input['district'] ?? null,
                    'taluka' => $input['taluka'] ?? null,
                    'village' => $input['village'] ?? null,
                    'pincode' => $input['pincode'] ?? null,
                    'occupation' => $input['occupation'] ?? null,
                    'land_area' => $input['land_area'] ?? null,
                    'primary_crops' => $input['primary_crops'] ?? null,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $db->insert('member_sponsors', [
                    'member_id' => $memberId,
                    'sponsor_id' => (int) $sponsor['id'],
                    'original_sponsor_id' => (int) $sponsor['id'],
                    'relationship' => 'direct',
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $db->insert('member_referrals', [
                    'sponsor_id' => (int) $sponsor['id'],
                    'referred_member_id' => $memberId,
                    'referral_code' => null,
                    'source' => $meta['source'] ?? 'direct',
                    'ip_address' => $meta['ip'] ?? Request::ip(),
                    'created_at' => $now,
                ]);

                $db->insert('wallet_accounts', [
                    'member_id' => $memberId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                $db->insert('member_kyc', [
                    'member_id' => $memberId,
                    'status' => 'not_submitted',
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                return $memberId;
            });
        } catch (\Throwable $e) {
            error_log('registration failed: ' . $e->getMessage());
            return ['ok' => false, 'message' => 'Registration could not be completed. Please try again.'];
        }

        (new TeamService($this->db))->recalculate($memberId);
        $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);

        // Auto allocate a customer record so members can shop and earn on their own purchases.
        $this->ensureCustomerRecord($member);

        Notification::send('member', $memberId, 'registration_welcome', 'Welcome to Saee Life Herbal',
            'Your partner account ' . $member['member_code'] . ' is active. Your sponsor is ' . ($sponsor['name'] ?? '') . '.',
            ['member_code' => $member['member_code'], 'sponsor_name' => $sponsor['name'] ?? '', 'referral_link' => Url::absoluteBase() . '/member/register?ref=' . $member['referral_code']],
            ['link' => 'dashboard', 'type' => 'success', 'icon' => 'fa-hand-sparkles']);
        if ((int) $sponsor['is_company'] !== 1) {
            Notification::send('member', (int) $sponsor['id'], 'new_referral', 'New Team Member Joined',
                $member['name'] . ' (' . $member['member_code'] . ') joined your team.',
                ['member_name' => $member['name'], 'member_code' => $member['member_code']],
                ['link' => 'referrals', 'type' => 'success', 'icon' => 'fa-user-plus']);
        }

        return ['ok' => true, 'message' => 'Registration successful. Your member ID is ' . $member['member_code'] . '.', 'member' => $member];
    }

    /** Members automatically get a customer record so checkout works with one account. */
    public function ensureCustomerRecord(array $member): void
    {
        $existing = $this->db->first('SELECT id FROM customers WHERE member_id = :m OR mobile = :mob', ['m' => $member['id'], 'mob' => $member['mobile']]);
        if ($existing) {
            if ((int) $this->db->value('SELECT COUNT(*) FROM customers WHERE id = :id AND member_id IS NULL', ['id' => $existing['id']]) > 0) {
                $this->db->update('customers', ['member_id' => $member['id']], 'id = :id', ['id' => $existing['id']]);
            }
            return;
        }
        try {
            $this->db->insert('customers', [
                'name' => $member['name'],
                'email' => $member['email'],
                'mobile' => $member['mobile'],
                'password_hash' => $member['password_hash'],
                'member_id' => $member['id'],
                'referred_by_member_id' => $member['sponsor_id'],
                'status' => 'active',
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('customer auto-create failed: ' . $e->getMessage());
        }
    }

    public static function generateReferralCode(string $memberCode): string
    {
        $base = strtoupper(preg_replace('/[^A-Z0-9]/i', '', $memberCode) ?? '');
        return $base . strtoupper(substr(bin2hex(random_bytes(3)), 0, 4));
    }

    public function referralLink(array $member): string
    {
        return Url::absoluteBase() . '/member/register?ref=' . urlencode((string) $member['referral_code']);
    }

    /** Admin sponsor correction with a permanent audit trail. */
    public function changeSponsor(int $memberId, ?int $newSponsorId, string $reason): array
    {
        $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        if (!$member) {
            return ['ok' => false, 'message' => 'Member not found.'];
        }
        if ($newSponsorId === $memberId) {
            return ['ok' => false, 'message' => 'A member cannot sponsor themselves.'];
        }
        if ($newSponsorId !== null) {
            $sponsor = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $newSponsorId]);
            if (!$sponsor) {
                return ['ok' => false, 'message' => 'New sponsor not found.'];
            }
            // prevent cycles: new sponsor must not be inside this member's downline
            $downline = (new TeamService($this->db))->downlineIds($memberId)['all'];
            if (in_array($newSponsorId, array_map('intval', $downline), true)) {
                return ['ok' => false, 'message' => 'Cannot assign a sponsor from within the member\'s own downline.'];
            }
        }
        if (trim($reason) === '') {
            return ['ok' => false, 'message' => 'A reason is required for sponsor corrections.'];
        }

        $this->db->transaction(function (Database $db) use ($member, $memberId, $newSponsorId, $reason) {
            $now = date('Y-m-d H:i:s');
            $db->update('members', [
                'sponsor_id' => $newSponsorId,
                'updated_at' => $now,
            ], 'id = :id', ['id' => $memberId]);
            $db->update('member_sponsors', [
                'sponsor_id' => $newSponsorId,
                'updated_at' => $now,
            ], 'member_id = :id', ['id' => $memberId]);
            $db->insert('member_sponsor_history', [
                'member_id' => $memberId,
                'old_sponsor_id' => $member['sponsor_id'],
                'new_sponsor_id' => $newSponsorId,
                'changed_by_admin_id' => AdminAuth::id(),
                'reason' => $reason,
                'created_at' => $now,
            ]);
        });

        (new TeamService($this->db))->recalculate($memberId);
        Audit::log('sponsor_changed', 'members', 'Sponsor corrected for ' . $member['member_code'] . ': ' . $reason, 'member', $memberId, [
            'old_sponsor_id' => $member['sponsor_id'], 'new_sponsor_id' => $newSponsorId,
        ]);
        return ['ok' => true, 'message' => 'Sponsor updated and audit trail recorded.'];
    }

    public function setStatus(int $memberId, string $status, string $reason = ''): array
    {
        if (!in_array($status, ['active', 'inactive', 'suspended'], true)) {
            return ['ok' => false, 'message' => 'Invalid status.'];
        }
        $this->db->update('members', [
            'status' => $status,
            'activated_at' => $status === 'active' ? date('Y-m-d H:i:s') : null,
            'updated_at' => date('Y-m-d H:i:s'),
        ], 'id = :id', ['id' => $memberId]);
        Audit::log('member_status_changed', 'members', 'Status set to ' . $status . ($reason ? ': ' . $reason : ''), 'member', $memberId);
        return ['ok' => true, 'message' => 'Member status updated.'];
    }

    /** Registration pipeline statistics used on dashboards. */
    public function stats(): array
    {
        $row = $this->db->first(
            "SELECT COUNT(*) AS total,
                    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) AS active,
                    SUM(CASE WHEN status = 'inactive' THEN 1 ELSE 0 END) AS inactive,
                    SUM(CASE WHEN status = 'suspended' THEN 1 ELSE 0 END) AS suspended,
                    SUM(CASE WHEN kyc_status = 'approved' THEN 1 ELSE 0 END) AS kyc_approved,
                    SUM(CASE WHEN kyc_status IN ('submitted','under_verification') THEN 1 ELSE 0 END) AS kyc_pending
             FROM members WHERE is_company = 0"
        ) ?? [];
        $today = (int) $this->db->value("SELECT COUNT(*) FROM members WHERE DATE(created_at) = :d", ['d' => date('Y-m-d')]);
        $month = (int) $this->db->value(
            'SELECT COUNT(*) FROM members WHERE ' . $this->db->monthExpression('created_at') . ' = :m',
            ['m' => date('Y-m')]
        );
        return [
            'total' => (int) ($row['total'] ?? 0),
            'active' => (int) ($row['active'] ?? 0),
            'inactive' => (int) ($row['inactive'] ?? 0),
            'suspended' => (int) ($row['suspended'] ?? 0),
            'kyc_approved' => (int) ($row['kyc_approved'] ?? 0),
            'kyc_pending' => (int) ($row['kyc_pending'] ?? 0),
            'new_today' => $today,
            'new_this_month' => $month,
        ];
    }
}
