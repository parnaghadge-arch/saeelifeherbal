<?php
/**
 * Atomic sequence generator used for member codes, order numbers, invoice numbers and
 * transaction codes. Transactions guarantee no duplicate document numbers.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Sequence
{
    public static function next(string $name, int $start = 1): int
    {
        $db = Database::instance();
        $ownTransaction = $db->transactionLevel() === 0;
        if ($ownTransaction) {
            $db->beginTransaction();
        }
        try {
            $row = $db->first('SELECT id, value FROM sequences WHERE name = :n', ['n' => $name]);
            if (!$row) {
                $db->insert('sequences', ['name' => $name, 'value' => $start, 'created_at' => date('Y-m-d H:i:s'), 'updated_at' => date('Y-m-d H:i:s')]);
                $value = $start;
            } else {
                $value = (int) $row['value'] + 1;
                $db->update('sequences', ['value' => $value, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $row['id']]);
            }
            if ($ownTransaction) {
                $db->commit();
            }
            return $value;
        } catch (\Throwable $e) {
            if ($ownTransaction) {
                $db->rollBack();
            }
            throw $e;
        }
    }

    public static function format(string $name, int $padding = 5, string $prefix = ''): string
    {
        return $prefix . str_pad((string) self::next($name), $padding, '0', STR_PAD_LEFT);
    }

    public static function memberCode(): string
    {
        $settings = self::mlmSettings();
        $prefix = $settings['member_id_prefix'] ?? 'SLH';
        $padding = (int) ($settings['member_id_padding'] ?? 5);
        return strtoupper($prefix) . str_pad((string) self::next('member_code'), $padding, '0', STR_PAD_LEFT);
    }

    public static function orderNumber(): string
    {
        $prefix = (string) Settings::get('order_prefix', 'SLH-ORD-');
        return $prefix . date('ym') . str_pad((string) self::next('order'), 5, '0', STR_PAD_LEFT);
    }

    public static function invoiceNumber(): string
    {
        $prefix = (string) Settings::get('invoice_prefix', 'SLH-INV-');
        return $prefix . date('ym') . str_pad((string) self::next('invoice'), 5, '0', STR_PAD_LEFT);
    }

    public static function code(string $prefix, string $name): string
    {
        return $prefix . '-' . date('ymd') . '-' . str_pad((string) self::next($name), 5, '0', STR_PAD_LEFT);
    }

    private static function mlmSettings(): array
    {
        try {
            return Database::instance()->first('SELECT * FROM mlm_settings WHERE id = 1') ?? [];
        } catch (\Throwable) {
            return [];
        }
    }
}
