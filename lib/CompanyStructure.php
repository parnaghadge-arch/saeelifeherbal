<?php
/**
 * The three company-owned accounts that sit at the top of the partner tree.
 *
 *   SAEE00001   (root — no sponsor)
 *     └── SAEE00002
 *           └── SAEE00003   ← the sponsor fallback: any partner who registers
 *                             without naming a sponsor is attached here
 *
 * All three carry the company name and are flagged `is_company = 1`, which keeps them
 * out of member counts, royalty evaluation and commission payouts.
 *
 * They are inserted with explicit member codes and therefore never touch the
 * `member_code` sequence, so the first real partner is still SLH000001.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class CompanyStructure
{
    /**
     * The chain, root first. `sponsor` is the member_code of the account above it.
     *
     * @var list<array{code:string, sponsor:string|null, mobile:string}>
     */
    public const CHAIN = [
        ['code' => 'SAEE00001', 'sponsor' => null,        'mobile' => '9000000001'],
        ['code' => 'SAEE00002', 'sponsor' => 'SAEE00001', 'mobile' => '9000000002'],
        ['code' => 'SAEE00003', 'sponsor' => 'SAEE00002', 'mobile' => '9000000003'],
    ];

    /** Every account in the chain is named after the company. */
    public const COMPANY_NAME = 'Saee Life Herbal';

    public function __construct(private Database $db)
    {
    }

    /**
     * The code every un-sponsored registration falls under.
     *
     * Deliberately the LAST account in the chain: the company wants new partners to land
     * at the bottom of its own structure, not at the root.
     */
    public static function fallbackCode(): string
    {
        $last = self::CHAIN[count(self::CHAIN) - 1] ?? null;
        return (string) ($last['code'] ?? DEFAULT_COMPANY_SPONSOR_ID);
    }

    /**
     * Create the three accounts and wire the sponsor chain.
     *
     * Idempotent — an account that already exists is left in place and only has its
     * sponsor link and company flag repaired — so this is safe to re-run.
     *
     * @return array<string,int> member_code => members.id
     */
    public function seed(): array
    {
        $wallet = new WalletService($this->db);
        $now = date('Y-m-d H:i:s');
        $ids = [];

        foreach (self::CHAIN as $entry) {
            $code = (string) $entry['code'];

            $sponsorId = null;
            if ($entry['sponsor'] !== null) {
                $found = $this->db->value('SELECT id FROM members WHERE member_code = :c', ['c' => $entry['sponsor']]);
                $sponsorId = $found !== null ? (int) $found : null;
            }

            $existing = $this->db->value('SELECT id FROM members WHERE member_code = :c', ['c' => $code]);
            if ($existing !== null) {
                $id = (int) $existing;
                // Repair the chain if an earlier run created the accounts out of order.
                $this->db->update(
                    'members',
                    [
                        'sponsor_id' => $sponsorId,
                        'original_sponsor_id' => $sponsorId,
                        'is_company' => 1,
                        'status' => 'active',
                        'updated_at' => $now,
                    ],
                    'id = :id',
                    ['id' => $id]
                );
                $ids[$code] = $id;
                continue;
            }

            $id = (int) $this->db->insert('members', [
                'member_code' => $code,
                'sponsor_id' => $sponsorId,
                'original_sponsor_id' => $sponsorId,
                'name' => self::COMPANY_NAME,
                'email' => strtolower($code) . '@saeelifeherbal.com',
                'mobile' => (string) $entry['mobile'],
                // Unlike real partners, these accounts do NOT get "password = member ID".
                // They are internal placeholders nobody is meant to sign in to, so the
                // password is random and never surfaced anywhere.
                'password_hash' => Security::hashPassword(Security::randomToken(24)),
                'role' => 'distributor',
                'status' => 'active',
                'is_company' => 1,
                'referral_code' => MemberService::generateReferralCode($code),
                'kyc_status' => 'approved',
                'is_payout_eligible' => 0,
                'joined_at' => $now,
                'activated_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $this->db->insert('member_profiles', [
                'member_id' => $id,
                'address' => 'Agri Business Park, Nagpur',
                'state' => 'Maharashtra',
                'district' => 'Nagpur',
                'pincode' => '440001',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $this->db->insert('member_kyc', [
                'member_id' => $id,
                'status' => 'approved',
                'created_at' => $now,
                'updated_at' => $now,
            ]);
            $wallet->account($id);

            $ids[$code] = $id;
        }

        // Point the fallback at the bottom of the chain, or un-sponsored partners would
        // be attached to the root instead.
        $this->db->update(
            'mlm_settings',
            ['company_sponsor_code' => self::fallbackCode(), 'updated_at' => $now],
            'id = 1'
        );
        MlmSettings::flush();

        (new TeamService($this->db))->recalculateAll();

        return $ids;
    }
}
