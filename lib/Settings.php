<?php
/**
 * Key/value settings store (site settings, CMS configuration, gateway credentials).
 * Supports grouped namespaces and in-request caching.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Settings
{
    private static array $cache = [];
    private static bool $loaded = false;

    public static function load(): void
    {
        if (self::$loaded) {
            return;
        }
        self::$cache = [];
        try {
            $db = Database::instance();
            if ($db->tableExists('settings')) {
                foreach ($db->select("SELECT setting_key, setting_value, setting_type FROM settings") as $row) {
                    self::$cache[$row['setting_key']] = self::cast($row['setting_value'], $row['setting_type']);
                }
            }
        } catch (\Throwable) {
            // Database not installed yet - defaults will be used.
        }
        self::$loaded = true;
    }

    private static function cast(?string $value, ?string $type): mixed
    {
        if ($value === null) {
            return null;
        }
        return match ($type) {
            'int', 'integer' => (int) $value,
            'bool', 'boolean' => filter_var($value, FILTER_VALIDATE_BOOLEAN),
            'json' => json_decode($value, true) ?: [],
            default => $value,
        };
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        self::load();
        return array_key_exists($key, self::$cache) && self::$cache[$key] !== null ? self::$cache[$key] : $default;
    }

    public static function set(string $key, mixed $value, string $type = 'string'): void
    {
        self::load();
        $db = Database::instance();
        $stored = match ($type) {
            'json' => json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'bool' => $value ? '1' : '0',
            default => is_bool($value) ? ($value ? '1' : '0') : (string) $value,
        };
        $existing = $db->first('SELECT id FROM settings WHERE setting_key = :k', ['k' => $key]);
        if ($existing) {
            $db->update('settings', ['setting_value' => $stored, 'setting_type' => $type, 'updated_at' => date('Y-m-d H:i:s')], 'id = :id', ['id' => $existing['id']]);
        } else {
            $db->insert('settings', [
                'setting_key' => $key,
                'setting_value' => $stored,
                'setting_type' => $type,
                'created_at' => date('Y-m-d H:i:s'),
                'updated_at' => date('Y-m-d H:i:s'),
            ]);
        }
        self::$cache[$key] = self::cast($stored, $type);
    }

    public static function forget(string $key): void
    {
        unset(self::$cache[$key]);
    }

    public static function all(): array
    {
        self::load();
        return self::$cache;
    }

    public static function many(array $keys): array
    {
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = self::get($k);
        }
        return $out;
    }

    public static function flush(): void
    {
        self::$loaded = false;
        self::$cache = [];
    }
}
