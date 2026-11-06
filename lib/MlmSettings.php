<?php
/**
 * MLM settings accessor.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class MlmSettings
{
    private static ?array $cache = null;

    public static function all(): array
    {
        if (self::$cache === null) {
            try {
                self::$cache = Database::instance()->first('SELECT * FROM mlm_settings WHERE id = 1') ?? self::defaults();
            } catch (\Throwable) {
                self::$cache = self::defaults();
            }
        }
        return self::$cache;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        return self::all()[$key] ?? $default;
    }

    public static function companySponsorCode(): string
    {
        return (string) self::get('company_sponsor_code', DEFAULT_COMPANY_SPONSOR_ID);
    }

    public static function planEnabled(): bool
    {
        return (int) self::get('plan_enabled', 0) === 1;
    }

    public static function commissionHoldDays(): int
    {
        return (int) self::get('commission_hold_days', 0);
    }

    public static function creditStatus(): string
    {
        return (string) self::get('auto_credit_status', 'available');
    }

    public static function flush(): void
    {
        self::$cache = null;
    }

    public static function defaults(): array
    {
        return [
            'company_sponsor_code' => DEFAULT_COMPANY_SPONSOR_ID,
            'plan_enabled' => 0,
            'commission_hold_days' => 0,
            'direct_levels' => 1,
            'grand_direct_levels' => 1,
            'commission_on_paid_only' => 1,
            'commission_on_cod' => 0,
            'auto_credit_status' => 'available',
            'royalty_auto_process' => 1,
            'royalty_requires_approval' => 1,
            'member_id_prefix' => 'SLH',
            'member_id_padding' => 5,
            'registration_enabled' => 1,
            'max_direct_referrals' => 0,
        ];
    }
}
