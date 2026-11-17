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
     * Whether the member has bought at least one product: any order in their own
     * name that was not cancelled. This is the genealogy tree's green/red state -
     * a member who only registered is red ("inactive") until the first purchase.
     */
    public static function hasPurchase(Database $db, int $memberId): bool
    {
        return (bool) $db->value(
            "SELECT 1 FROM orders WHERE member_id = :m AND status <> 'cancelled' LIMIT 1",
            ['m' => $memberId]
        );
    }

    /**
     * Payout gate for members who have not purchased yet. Their downline commission
     * is still calculated and credited normally - it simply cannot be paid out (by
     * the admin or anyone else) until the first purchase turns the member green.
     * Returns null once the member is active.
     */
    public static function purchaseBlocker(Database $db, int $memberId): ?string
    {
        return self::hasPurchase($db, $memberId)
            ? null
            : 'No product purchase yet — payout unlocks after the first purchase (commission keeps being calculated meanwhile).';
    }

    /** Purchase counters for the genealogy detail window. */
    public static function purchaseSummary(Database $db, int $memberId): array
    {
        $row = $db->first(
            "SELECT COUNT(*) AS purchases, COALESCE(SUM(grand_total), 0) AS spent
               FROM orders WHERE member_id = :m AND status <> 'cancelled'",
            ['m' => $memberId]
        ) ?? [];
        return ['purchases' => (int) ($row['purchases'] ?? 0), 'spent' => (float) ($row['spent'] ?? 0)];
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

    /**
     * The account every un-sponsored registration is attached to.
     *
     * The configured code wins. There are three company accounts (SAEE00001 -> 00002 ->
     * 00003) and the business wants new partners at the BOTTOM of that chain, so picking
     * "the first is_company row" would silently attach everyone to the root instead.
     */
    public function companySponsor(): ?array
    {
        $row = $this->db->first('SELECT * FROM members WHERE member_code = :c', ['c' => MlmSettings::companySponsorCode()]);
        if ($row) {
            return $row;
        }
        return $this->db->first('SELECT * FROM members WHERE is_company = 1 ORDER BY id DESC LIMIT 1');
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
        // allow_root lets an administrator deliberately create a member with NO sponsor, which
        // starts a new tree. Self-registration never does this: a public registrant with no
        // sponsor code is still attached to the company sponsor.
        $allowRoot = !empty($input['allow_root']);
        $sponsor = $this->resolveSponsor($sponsorInput);
        if ($sponsorInput !== '' && !$sponsor) {
            return ['ok' => false, 'message' => 'The sponsor ID you entered does not exist.', 'errors' => ['sponsor_code' => ['Invalid sponsor ID.']]];
        }
        if (!$sponsor && !$allowRoot) {
            $sponsor = $this->companySponsor();
        }
        if (!$sponsor && !$allowRoot) {
            return ['ok' => false, 'message' => 'Company sponsor is not configured. Please contact the administrator.'];
        }
        if ($sponsor && isset($input['member_id']) && (int) $input['member_id'] === (int) $sponsor['id']) {
            return ['ok' => false, 'message' => 'A member cannot sponsor themselves.', 'errors' => ['sponsor_code' => ['Invalid sponsor.']]];
        }
        // A sponsor who is inactive or suspended would receive commissions they cannot use, and
        // a suspended sponsor's tree is frozen by policy.
        if ($sponsor && (string) $sponsor['status'] !== 'active' && empty($input['force_status_sponsor'])) {
            return [
                'ok' => false,
                'message' => "That sponsor's account is " . $sponsor['status'] . ', so members cannot be added under it.',
                'errors' => ['sponsor_code' => ['Sponsor is not active.']],
            ];
        }

        $maxDirect = (int) MlmSettings::get('max_direct_referrals', 0);
        if ($maxDirect > 0) {
            $directCount = (int) $this->db->value('SELECT COUNT(*) FROM members WHERE sponsor_id = :s', ['s' => $sponsor['id']]);
            if ($directCount >= $maxDirect) {
                return ['ok' => false, 'message' => 'This sponsor has reached the maximum number of direct referrals.'];
            }
        }

        // The password is not chosen by the registrant: it is generated as the member ID
        // itself, so a partner has exactly one credential to remember. The plain value only
        // ever exists in this response, which is why the welcome card is shown once.
        $plainPassword = '';

        try {
            $memberId = $this->db->transaction(function (Database $db) use ($input, $mobile, $email, $sponsor, $meta, &$plainPassword) {
                $code = Sequence::memberCode();
                $plainPassword = $code;
                $referralCode = self::generateReferralCode($code);
                $now = date('Y-m-d H:i:s');

                $memberId = $db->insert('members', [
                    'member_code' => $code,
                    'sponsor_id' => $sponsor !== null ? (int) $sponsor['id'] : null,
                    'original_sponsor_id' => $sponsor !== null ? (int) $sponsor['id'] : null,
                    'name' => trim((string) $input['name']),
                    'email' => $email !== '' ? $email : null,
                    'mobile' => $mobile,
                    'password_hash' => Security::hashPassword($plainPassword),
                    // Read the value once: the old form guarded the check with ?? but then
                    // read $input['role'] raw in the true branch, so a caller that omitted
                    // 'role' died on the NOT NULL constraint instead of defaulting.
                    'role' => in_array($role = (string) ($input['role'] ?? 'farmer'), ['farmer', 'distributor', 'retailer'], true) ? $role : 'farmer',
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
                    'sponsor_id' => $sponsor !== null ? (int) $sponsor['id'] : null,
                    'original_sponsor_id' => $sponsor !== null ? (int) $sponsor['id'] : null,
                    'relationship' => 'direct',
                    'assigned_at' => $now,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);

                // A root member (no sponsor) has no referral record: there is no one to credit.
                if ($sponsor !== null) {
                    $db->insert('member_referrals', [
                        'sponsor_id' => (int) $sponsor['id'],
                        'referred_member_id' => $memberId,
                        'referral_code' => null,
                        'source' => $meta['source'] ?? 'direct',
                        'ip_address' => $meta['ip'] ?? Request::ip(),
                        'created_at' => $now,
                    ]);
                }

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
            'Your partner account ' . $member['member_code'] . ' is active.'
                . ($sponsor !== null ? ' Your sponsor is ' . $sponsor['name'] . '.' : ' You are the top of your own tree.'),
            ['member_code' => $member['member_code'], 'sponsor_name' => $sponsor['name'] ?? '', 'referral_link' => self::shareLink($member['referral_code'])],
            ['link' => 'dashboard', 'type' => 'success', 'icon' => 'fa-hand-sparkles']);
        if ($sponsor !== null && (int) $sponsor['is_company'] !== 1) {
            Notification::send('member', (int) $sponsor['id'], 'new_referral', 'New Team Member Joined',
                $member['name'] . ' (' . $member['member_code'] . ') joined your team.',
                ['member_name' => $member['name'], 'member_code' => $member['member_code']],
                ['link' => 'referrals', 'type' => 'success', 'icon' => 'fa-user-plus']);
        }

        return [
            'ok' => true,
            'message' => 'Registration successful. Your member ID is ' . $member['member_code'] . '.',
            'member' => $member,
            // Everything the "save your details" card needs, gathered in one place so the
            // registration page, the admin create form and the API all show the same thing.
            'welcome' => $this->welcomeDetails(
                $member,
                $plainPassword,
                $sponsor,
                $sponsor === null
            ),
        ];
    }

    /**
     * The shareable referral link for a member's code.
     *
     * Absolute, because it is copied into WhatsApp and must work pasted anywhere.
     */
    public static function shareLink(?string $referralCode): string
    {
        $code = trim((string) $referralCode);
        return $code === '' ? '' : Url::absoluteBase() . '/member/register?ref=' . rawurlencode($code);
    }

    /**
     * Details a new member needs to keep: login credentials, sponsor, member ID and share link.
     *
     * The plain password is only ever available at this moment, which is why the card is shown
     * immediately after creation and never again. Nothing here is persisted.
     *
     * @return array<string,mixed>
     */
    public function welcomeDetails(array $member, string $plainPassword, ?array $sponsor, bool $noSponsor = false): array
    {
        $code = (string) ($member['member_code'] ?? '');
        return [
            'member_id' => (int) ($member['id'] ?? 0),
            'member_code' => $code,
            'name' => (string) ($member['name'] ?? ''),
            'username' => self::usernameFor($member),
            'password' => $plainPassword,
            'mobile' => (string) ($member['mobile'] ?? ''),
            'email' => (string) ($member['email'] ?? ''),
            'role' => ucfirst((string) ($member['role'] ?? '')),
            'status' => (string) ($member['status'] ?? ''),
            'joined_at' => (string) ($member['joined_at'] ?? ''),
            'sponsor_code' => $sponsor !== null ? (string) ($sponsor['member_code'] ?? '') : '',
            'sponsor_name' => $sponsor !== null ? (string) ($sponsor['name'] ?? '') : '',
            'root_of_own_tree' => $noSponsor || $sponsor === null,
            'referral_code' => (string) ($member['referral_code'] ?? ''),
            'share_link' => self::shareLink((string) ($member['referral_code'] ?? '')),
            'login_url' => Url::absoluteBase() . '/member/login',
        ];
    }

    /**
     * What a member signs in with.
     *
     * MemberAuth::attempt() accepts the member code, the mobile number or the email, so all three
     * are valid "usernames". The member code is shown as the primary one.
     */
    public static function usernameFor(array $member): string
    {
        return (string) ($member['member_code'] ?? '');
    }

    /**
     * Create a member and, optionally, buy products in their name — as one unit of work.
     *
     * Used by the admin "new member" screen. Without this the two steps could half-succeed: a
     * member created with no order, or an order against a member that does not exist. The member
     * is created first (registration runs its own transaction), then the purchase; if the
     * purchase fails the member is removed again and the whole attempt is reported as failed.
     *
     * MLM and payout rules are not re-implemented here: the purchase goes through
     * MemberPurchaseService, so commissions, wallet movements and royalty are calculated exactly
     * as they are for any other order.
     *
     * @param array<string,mixed> $input    member fields, see register()
     * @param array<int,int>      $lines    productId => quantity (empty = member only)
     * @param array<string,mixed> $purchase payment_mode plus optional customer/address fields
     * @param array<string,mixed> $meta     source and ip
     * @return array<string,mixed>
     */
    public function createWithPurchase(array $input, array $lines, array $purchase, array $meta = []): array
    {
        $result = $this->register($input, $meta);
        if (empty($result['ok'])) {
            return $result;
        }

        $member = (array) $result['member'];
        $memberId = (int) $member['id'];
        $quantities = [];
        foreach ($lines as $productId => $qty) {
            if ((int) $qty > 0) {
                $quantities[(int) $productId] = (int) $qty;
            }
        }
        if ($quantities === []) {
            // Members-only creation: nothing else to do.
            $result['purchase'] = null;
            return $result;
        }

        $details = [
            'payment_mode' => (string) ($purchase['payment_mode'] ?? ''),
            'notes' => (string) ($purchase['notes'] ?? 'Opening purchase for ' . ($member['member_code'] ?? '')),
            'customer_name' => (string) ($purchase['customer_name'] ?? ($member['name'] ?? '')),
            'customer_mobile' => (string) ($purchase['customer_mobile'] ?? ($member['mobile'] ?? '')),
            'address_line1' => (string) ($purchase['address_line1'] ?? ''),
            'city' => (string) ($purchase['city'] ?? ''),
            'state' => (string) ($purchase['state'] ?? ''),
            'pincode' => (string) ($purchase['pincode'] ?? ''),
        ];

        $order = (new MemberPurchaseService($this->db))->create(0, 'admin', $memberId, $quantities, $details);
        if (empty($order['ok'])) {
            // Roll the member back so the admin can correct the basket and try again, instead of
            // being left with an account nobody meant to create.
            $this->removeNewMember($memberId);
            return [
                'ok' => false,
                'message' => 'The purchase failed, so the member was not created: ' . (string) ($order['message'] ?? 'unknown error'),
                'errors' => [],
            ];
        }

        $result['purchase'] = $order;
        $result['message'] = 'Member ' . ($member['member_code'] ?? '') . ' created, and ' . ($order['message'] ?? 'the opening purchase was recorded.');
        return $result;
    }

    /**
     * Delete a member created moments ago, when the rest of the operation failed.
     *
     * Deliberately narrow: only rows this very operation created are removed, and any remaining
     * order is left untouched (it cannot exist, because the purchase is what failed).
     */
    private function removeNewMember(int $memberId): void
    {
        try {
            $this->db->transaction(function (Database $db) use ($memberId): void {
                foreach (['member_profiles', 'member_sponsors', 'member_referrals', 'wallet_accounts', 'member_kyc'] as $table) {
                    $column = $table === 'member_referrals' ? 'referred_member_id' : 'member_id';
                    $db->execute(sprintf('DELETE FROM %s WHERE %s = :id', $db->quoteIdentifier($table), $db->quoteIdentifier($column)), ['id' => $memberId]);
                }
                $db->execute('DELETE FROM customers WHERE member_id = :id', ['id' => $memberId]);
                $db->execute('DELETE FROM members WHERE id = :id', ['id' => $memberId]);
            });
        } catch (\Throwable $e) {
            error_log('could not roll back member #' . $memberId . ': ' . $e->getMessage());
        }
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

    /** Placeholders for a dynamic IN list (:prefix0, :prefix1, ...). */
    private static function inClause(string $column, string $prefix, int $count): string
    {
        $keys = [];
        for ($i = 0; $i < $count; $i++) {
            $keys[] = ':' . $prefix . $i;
        }
        return $column . ' IN (' . implode(', ', $keys) . ')';
    }

    /** Params matching inClause() for the same prefix, merged into $params. */
    private static function inParams(string $prefix, array $values, array $params = []): array
    {
        foreach (array_values($values) as $i => $value) {
            $params[$prefix . $i] = $value;
        }
        return $params;
    }

    /** Orders touched by a member deletion: what they bought, placed or was credited for. */
    private function orderScope(int $memberId, ?int $customerId): array
    {
        $where = '(member_id = :m OR referral_member_id = :m OR created_by_member_id = :m';
        $params = ['m' => $memberId];
        if ($customerId !== null) {
            $where .= ' OR customer_id = :c';
            $params['c'] = $customerId;
        }
        return [$where . ')', $params];
    }

    /** Commission rows removed with the member: their own earnings plus everything their
     *  purchases paid out to the upline (level 1 direct, level 2 grand direct). */
    private function commissionScope(int $memberId, string $orderWhere, array $orderParams): array
    {
        return [
            '(member_id = :cm OR buyer_member_id = :cm OR order_id IN (SELECT id FROM orders WHERE ' . $orderWhere . '))',
            ['cm' => $memberId] + $orderParams,
        ];
    }

    /** Where the team moves when a member is deleted: their sponsor, or the company root. */
    private function teamDestination(array $member): ?array
    {
        if (!empty($member['sponsor_id'])) {
            return $this->db->first(
                'SELECT id, member_code, name FROM members WHERE id = :id',
                ['id' => (int) $member['sponsor_id']]
            );
        }
        $company = $this->companySponsor();
        return $company
            ? ['id' => (int) $company['id'], 'member_code' => (string) $company['member_code'], 'name' => (string) $company['name']]
            : null;
    }

    /**
     * Everything that will be altered by a member deletion, before it happens: which rows are
     * deleted, which rows are changed, whose commission totals are recalculated and where the
     * team moves. Rendered by admin/member-delete.php as the confirmation screen.
     */
    public function deletePreview(int $memberId): array
    {
        $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        if (!$member) {
            return [];
        }
        $sponsor = $member['sponsor_id']
            ? $this->db->first('SELECT id, member_code, name, sponsor_id FROM members WHERE id = :id', ['id' => (int) $member['sponsor_id']])
            : null;
        $grandSponsor = ($sponsor && $sponsor['sponsor_id'])
            ? $this->db->first('SELECT id, member_code, name, sponsor_id FROM members WHERE id = :id', ['id' => (int) $sponsor['sponsor_id']])
            : null;
        $target = $this->teamDestination((array) $member);
        $customer = $this->db->first('SELECT * FROM customers WHERE member_id = :m LIMIT 1', ['m' => $memberId]);
        $customerId = $customer ? (int) $customer['id'] : null;

        [$orderWhere, $orderParams] = $this->orderScope($memberId, $customerId);
        [$commissionWhere, $commissionParams] = $this->commissionScope($memberId, $orderWhere, $orderParams);

        $one = fn (string $sql, array $p = []): int => (int) $this->db->value($sql, $p);
        $sum = fn (string $sql, array $p = []): float => (float) $this->db->value($sql, $p);

        $wallet = $this->db->first('SELECT available_balance, total_earned FROM wallet_accounts WHERE member_id = :m', ['m' => $memberId]);
        $commissionsEarned = $this->db->first(
            'SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS t FROM commission_transactions WHERE member_id = :m',
            ['m' => $memberId]
        );
        $payouts = $this->db->first(
            "SELECT COUNT(*) AS c, COALESCE(SUM(net_amount),0) AS requested,
                    COALESCE(SUM(CASE WHEN status = 'paid' THEN net_amount ELSE 0 END),0) AS paid,
                    COALESCE(SUM(CASE WHEN status IN ('pending','approved','processing') THEN 1 ELSE 0 END),0) AS open
               FROM payout_requests WHERE member_id = :m",
            ['m' => $memberId]
        );
        $orderRow = $this->db->first(
            'SELECT COUNT(*) AS c, COALESCE(SUM(grand_total),0) AS t FROM orders WHERE ' . $orderWhere,
            $orderParams
        );

        // Who loses what: every commission the deletion removes that is NOT the member's own
        // (those go with them) - i.e. what their purchases paid to the upline.
        $paidToOthers = $this->db->select(
            'SELECT member_id, commission_type, COUNT(*) AS c, COALESCE(SUM(amount),0) AS t
               FROM commission_transactions
              WHERE ' . $commissionWhere . ' AND member_id <> :m2
              GROUP BY member_id, commission_type',
            $commissionParams + ['m2' => $memberId]
        );

        $recalculate = [];
        foreach (array_filter([$sponsor, $grandSponsor]) as $person) {
            $lost = $this->db->first(
                'SELECT COUNT(*) AS c, COALESCE(SUM(amount),0) AS t FROM commission_transactions
                  WHERE member_id = :s AND ' . $commissionWhere,
                ['s' => (int) $person['id']] + $commissionParams
            );
            $w = $this->db->first('SELECT available_balance, total_earned FROM wallet_accounts WHERE member_id = :m', ['m' => (int) $person['id']]);
            $recalculate[] = [
                'member' => $person,
                'role' => ($sponsor && (int) $person['id'] === (int) $sponsor['id']) ? 'Sponsor' : 'Grand sponsor',
                'lost_count' => (int) ($lost['c'] ?? 0),
                'lost_total' => (float) ($lost['t'] ?? 0),
                'available_balance' => (float) ($w['available_balance'] ?? 0),
                'total_earned' => (float) ($w['total_earned'] ?? 0),
            ];
        }

        return [
            'member' => $member,
            'sponsor' => $sponsor,
            'grand_sponsor' => $grandSponsor,
            'target' => $target,
            'own' => [
                'profile' => $one('SELECT COUNT(*) FROM member_profiles WHERE member_id = :m', ['m' => $memberId]),
                'kyc' => $one('SELECT COUNT(*) FROM member_kyc WHERE member_id = :m', ['m' => $memberId]),
                'bank_accounts' => $one('SELECT COUNT(*) FROM member_bank_accounts WHERE member_id = :m', ['m' => $memberId]),
                'sponsor_edges' => $one('SELECT COUNT(*) FROM member_sponsors WHERE member_id = :m', ['m' => $memberId]),
                'referral_records' => $one('SELECT COUNT(*) FROM member_referrals WHERE referred_member_id = :m OR sponsor_id = :m', ['m' => $memberId]),
                'sponsor_history' => $one('SELECT COUNT(*) FROM member_sponsor_history WHERE member_id = :m', ['m' => $memberId]),
                'referral_clicks' => $one('SELECT COUNT(*) FROM member_referral_clicks WHERE member_id = :m', ['m' => $memberId]),
                'wallet_transactions' => $one('SELECT COUNT(*) FROM wallet_transactions WHERE member_id = :m', ['m' => $memberId]),
                'wallet_balance' => (float) ($wallet['available_balance'] ?? 0),
                'wallet_earned' => (float) ($wallet['total_earned'] ?? 0),
                'commissions' => (int) ($commissionsEarned['c'] ?? 0),
                'commissions_total' => (float) ($commissionsEarned['t'] ?? 0),
                'payouts' => (int) ($payouts['c'] ?? 0),
                'payouts_requested' => (float) ($payouts['requested'] ?? 0),
                'payouts_paid' => (float) ($payouts['paid'] ?? 0),
                'payouts_open' => (int) ($payouts['open'] ?? 0),
                'royalty' => $one('SELECT COUNT(*) FROM royalty_achievements WHERE member_id = :m', ['m' => $memberId]),
            ],
            'orders' => [
                'count' => (int) ($orderRow['c'] ?? 0),
                'grand_total' => (float) ($orderRow['t'] ?? 0),
                'items' => $one('SELECT COUNT(*) FROM order_items WHERE order_id IN (SELECT id FROM orders WHERE ' . $orderWhere . ')', $orderParams),
                'commissions' => $one('SELECT COUNT(*) FROM commission_transactions WHERE ' . $commissionWhere, $commissionParams),
                'commissions_total' => $sum('SELECT COALESCE(SUM(amount),0) FROM commission_transactions WHERE ' . $commissionWhere, $commissionParams),
            ],
            'paid_to_others' => $paidToOthers,
            'customer' => $customer ? [
                'row' => $customer,
                'addresses' => $one('SELECT COUNT(*) FROM customer_addresses WHERE customer_id = :c', ['c' => $customerId]),
                'carts' => $one('SELECT COUNT(*) FROM carts WHERE customer_id = :c', ['c' => $customerId]),
                'wishlist' => $one('SELECT COUNT(*) FROM wishlists WHERE customer_id = :c', ['c' => $customerId]),
            ] : null,
            'team' => [
                'count' => $one('SELECT COUNT(*) FROM members WHERE sponsor_id = :m', ['m' => $memberId]),
                'sample' => $this->db->select(
                    'SELECT id, member_code, name, status FROM members WHERE sponsor_id = :m ORDER BY id LIMIT 8',
                    ['m' => $memberId]
                ),
            ],
            'misc' => [
                'notifications' => $one(
                    'SELECT COUNT(*) FROM notifications WHERE (recipient_type = \'member\' AND recipient_id = :m)'
                    . ($customer ? ' OR (recipient_type = \'customer\' AND recipient_id = :c)' : ''),
                    $customer ? ['m' => $memberId, 'c' => $customerId] : ['m' => $memberId]
                ),
                'login_attempts' => $one(
                    "SELECT COUNT(*) FROM login_attempts WHERE (scope = 'member' AND identifier = :code)"
                    . ($customer ? " OR (scope = 'customer' AND (identifier = :mob OR identifier = :email))" : ''),
                    $customer
                        ? ['code' => (string) $member['member_code'], 'mob' => (string) ($customer['mobile'] ?? ''), 'email' => (string) ($customer['email'] ?? '')]
                        : ['code' => (string) $member['member_code']]
                ),
            ],
            'alterations' => [
                'clear_referred_by' => $one(
                    'SELECT COUNT(*) FROM customers WHERE referred_by_member_id = :m' . ($customer ? ' AND id <> :c' : ''),
                    $customer ? ['m' => $memberId, 'c' => $customerId] : ['m' => $memberId]
                ),
                'clear_history_refs' => $one(
                    'SELECT COUNT(*) FROM member_sponsor_history WHERE (old_sponsor_id = :m OR new_sponsor_id = :m) AND member_id <> :m',
                    ['m' => $memberId]
                ),
            ],
            'recalculate' => $recalculate,
        ];
    }

    /**
     * Hard-delete a member with everything attached to them, re-home their team on their
     * sponsor and recalculate the sponsor and grand sponsor commission totals - the only two
     * levels the plan pays on purchases (level 1 direct, level 2 grand direct).
     */
    public function deleteMember(int $memberId, string $reason): array
    {
        $member = $this->db->first('SELECT * FROM members WHERE id = :id', ['id' => $memberId]);
        if (!$member) {
            return ['ok' => false, 'message' => 'Member not found.'];
        }
        if ((int) ($member['is_company'] ?? 0) === 1) {
            return ['ok' => false, 'message' => 'The company sponsor account cannot be deleted.'];
        }
        if (trim($reason) === '') {
            return ['ok' => false, 'message' => 'A reason is required for deletions.'];
        }

        $sponsor = $member['sponsor_id']
            ? $this->db->first('SELECT id, member_code, name, sponsor_id FROM members WHERE id = :id', ['id' => (int) $member['sponsor_id']])
            : null;
        $grandSponsor = ($sponsor && $sponsor['sponsor_id'])
            ? $this->db->first('SELECT id, member_code, name FROM members WHERE id = :id', ['id' => (int) $sponsor['sponsor_id']])
            : null;
        $target = $this->teamDestination((array) $member);
        $customer = $this->db->first('SELECT * FROM customers WHERE member_id = :m LIMIT 1', ['m' => $memberId]);
        $customerId = $customer ? (int) $customer['id'] : null;
        [$orderWhere, $orderParams] = $this->orderScope($memberId, $customerId);
        [$commissionWhere, $commissionParams] = $this->commissionScope($memberId, $orderWhere, $orderParams);
        $children = array_map('intval', (array) $this->db->column('SELECT id FROM members WHERE sponsor_id = :m', ['m' => $memberId]));

        $summary = $this->db->transaction(function (Database $db) use (
            $member, $memberId, $reason, $sponsor, $grandSponsor, $target, $customer, $customerId,
            $orderWhere, $orderParams, $commissionWhere, $commissionParams, $children
        ): array {
            $now = date('Y-m-d H:i:s');
            $adminId = AdminAuth::id();
            $newSponsorId = isset($target['id']) ? (int) $target['id'] : null;

            // 0. Clear dangling sponsor pointers in OTHER members' history rows first - before
            //    the move-history rows below intentionally record the deleted member as the
            //    old sponsor (those rows must keep that pointer).
            $db->update('member_sponsor_history', ['old_sponsor_id' => null], 'old_sponsor_id = :m', ['m' => $memberId]);
            $db->update('member_sponsor_history', ['new_sponsor_id' => null], 'new_sponsor_id = :m', ['m' => $memberId]);

            // 1. Re-home the team FIRST: members.sponsor_id and member_sponsors.sponsor_id are
            //    ON DELETE RESTRICT, so these rows must point elsewhere before the member goes.
            if ($children !== []) {
                $db->update('members', ['sponsor_id' => $newSponsorId, 'updated_at' => $now], 'sponsor_id = :m', ['m' => $memberId]);
                $db->update('member_sponsors', ['sponsor_id' => $newSponsorId, 'updated_at' => $now], 'sponsor_id = :m', ['m' => $memberId]);
                foreach ($children as $childId) {
                    $db->insert('member_sponsor_history', [
                        'member_id' => $childId,
                        'old_sponsor_id' => $memberId,
                        'new_sponsor_id' => $newSponsorId,
                        'changed_by_admin_id' => $adminId,
                        'reason' => 'Upstream member ' . (string) $member['member_code'] . ' deleted: ' . trim($reason),
                        'created_at' => $now,
                    ]);
                }
            }

            // 2. Wallet entries that reference commissions being removed (the sponsor's and
            //    grand sponsor's credits - and any reversal debits against them).
            $commissionIds = array_map('intval', (array) $db->column('SELECT id FROM commission_transactions WHERE ' . $commissionWhere, $commissionParams));
            $walletRows = 0;
            if ($commissionIds !== []) {
                $walletRows = $db->delete(
                    'wallet_transactions',
                    "reference_type = 'commission' AND " . self::inClause('reference_id', 'cw', count($commissionIds)),
                    self::inParams('cw', $commissionIds)
                );
            }

            // 3. The commissions themselves: the member's own earnings plus what their
            //    purchases paid out to everyone else.
            $commissionRows = $db->delete('commission_transactions', $commissionWhere, $commissionParams);

            // 4. Royalty rows owned by the member or derived from a removed commission.
            $royaltyWhere = 'member_id = :m';
            $royaltyParams = ['m' => $memberId];
            if ($commissionIds !== []) {
                $royaltyWhere = '(' . $royaltyWhere . ' OR ' . self::inClause('commission_transaction_id', 'rc', count($commissionIds)) . ')';
                $royaltyParams = self::inParams('rc', $commissionIds, $royaltyParams);
            }
            $db->delete('royalty_achievements', $royaltyWhere, $royaltyParams);

            // 5. Payouts - payout_requests.member_id is ON DELETE RESTRICT, so these must go
            //    before the member row.
            $db->delete('payout_transactions', 'member_id = :m', ['m' => $memberId]);
            $payoutRows = $db->delete('payout_requests', 'member_id = :m', ['m' => $memberId]);

            // 6. Orders attributed to the member (items, payments and status history follow).
            $orderIds = array_map('intval', (array) $db->column('SELECT id FROM orders WHERE ' . $orderWhere, $orderParams));
            if ($orderIds !== []) {
                foreach (['order_status_history', 'order_payments', 'order_items'] as $table) {
                    $db->delete($table, self::inClause('order_id', 'oi', count($orderIds)), self::inParams('oi', $orderIds));
                }
            }
            $orderRows = $db->delete('orders', $orderWhere, $orderParams);

            // 7. The linked customer login - addresses, carts and wishlists go with it.
            if ($customer) {
                $cid = (int) $customer['id'];
                $cartIds = array_map('intval', (array) $db->column('SELECT id FROM carts WHERE customer_id = :c', ['c' => $cid]));
                if ($cartIds !== []) {
                    $db->delete('cart_items', self::inClause('cart_id', 'ct', count($cartIds)), self::inParams('ct', $cartIds));
                }
                $db->delete('carts', 'customer_id = :c', ['c' => $cid]);
                $db->delete('customer_addresses', 'customer_id = :c', ['c' => $cid]);
                $db->delete('wishlists', 'customer_id = :c', ['c' => $cid]);
                $db->delete('customers', 'id = :c', ['c' => $cid]);
            }

            // 8. Notifications and login-throttle rows that name the member or their customer.
            $notifWhere = "(recipient_type = 'member' AND recipient_id = :m)";
            $notifParams = ['m' => $memberId];
            if ($customer) {
                $notifWhere .= " OR (recipient_type = 'customer' AND recipient_id = :c)";
                $notifParams['c'] = $customerId;
            }
            $db->delete('notifications', $notifWhere, $notifParams);
            $db->delete('login_attempts', "scope = 'member' AND identifier = :code", ['code' => (string) $member['member_code']]);
            if ($customer) {
                $db->delete('login_attempts', "scope = 'customer' AND (identifier = :mob OR identifier = :email)", [
                    'mob' => (string) ($customer['mobile'] ?? ''),
                    'email' => (string) ($customer['email'] ?? ''),
                ]);
            }

            // 9. Everything the member owns directly. MySQL cascades most of this with the
            //     member row, but the explicit deletes keep behaviour identical everywhere.
            foreach (['member_referral_clicks', 'member_sponsor_history', 'member_sponsors', 'member_bank_accounts', 'member_kyc', 'member_profiles', 'wallet_accounts'] as $table) {
                $db->delete($table, 'member_id = :m', ['m' => $memberId]);
            }
            $db->delete('member_referrals', 'referred_member_id = :m OR sponsor_id = :m', ['m' => $memberId]);
            $memberWalletRows = $db->delete('wallet_transactions', 'member_id = :m', ['m' => $memberId]);
            $db->delete('members', 'id = :id', ['id' => $memberId]);

            return [
                'orders' => $orderRows,
                'commissions' => $commissionRows,
                'wallet_rows' => $walletRows + $memberWalletRows,
                'payouts' => $payoutRows,
                'moved' => count($children),
            ];
        });

        // Recalculate the commission on the sponsor and grand sponsor after deletion: their
        // level 1 / level 2 earnings from this member's purchases are gone from the ledger,
        // so the wallet aggregates must be rebuilt from what remains.
        foreach (array_filter([$sponsor, $grandSponsor]) as $person) {
            if ((int) $person['id'] !== $memberId) {
                (new WalletService($this->db))->recalculate((int) $person['id']);
            }
        }
        // Team counters (direct / downline counts) of the sponsor chain change with the move.
        $anchor = $sponsor ?: ($children !== [] ? $target : null);
        if ($anchor && (int) $anchor['id'] !== $memberId) {
            (new TeamService($this->db))->recalculate((int) $anchor['id'], true);
        }

        $message = sprintf(
            'Member %s deleted: %d order(s), %d commission entrie(s), %d wallet transaction(s), %d payout request(s) removed; %d team member(s) moved to %s. Sponsor and grand sponsor commission totals recalculated.',
            (string) $member['member_code'],
            (int) ($summary['orders'] ?? 0),
            (int) ($summary['commissions'] ?? 0),
            (int) ($summary['wallet_rows'] ?? 0),
            (int) ($summary['payouts'] ?? 0),
            (int) ($summary['moved'] ?? 0),
            $target ? (string) $target['member_code'] : 'company placement'
        );
        Audit::log('member_deleted', 'members', $message . ' Reason: ' . trim($reason), 'member', $memberId, [
            'sponsor_id' => $sponsor['id'] ?? null,
            'grand_sponsor_id' => $grandSponsor['id'] ?? null,
            'summary' => $summary,
        ]);
        return ['ok' => true, 'message' => $message, 'summary' => $summary];
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
        // Comparing a date expression against a bound parameter needs the expression pinned to
        // the column collation on MySQL, otherwise a host whose server default is
        // utf8mb4_general_ci fails with 1267 Illegal mix of collations.
        $today = (int) $this->db->value(
            'SELECT COUNT(*) FROM members WHERE ' . $this->db->dateExpression('created_at') . ' = :d',
            ['d' => date('Y-m-d')]
        );
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
