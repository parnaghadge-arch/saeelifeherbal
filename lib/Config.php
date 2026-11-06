<?php
/**
 * Configuration container. Loads base + environment specific configuration once.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Config
{
    private static ?array $items = null;

    public static function all(): array
    {
        if (self::$items === null) {
            $base = self::loadDatabase();
            $base['database'] = $base;
            $overrides = [];
            $envFile = CONFIG_PATH . '/env.php';
            if (is_file($envFile)) {
                $overrides = require $envFile;
            }
            self::$items = array_replace_recursive($base, $overrides);
            self::$items['base_path'] = APP_ROOT;
        }
        return self::$items;
    }

    public static function get(string $key, mixed $default = null): mixed
    {
        $value = self::all();
        foreach (explode('.', $key) as $segment) {
            if (!is_array($value) || !array_key_exists($segment, $value)) {
                return $default;
            }
            $value = $value[$segment];
        }
        return $value;
    }

    public static function set(string $key, mixed $value): void
    {
        self::all();
        $segments = explode('.', $key);
        $ref = &self::$items;
        foreach ($segments as $segment) {
            if (!isset($ref[$segment]) || !is_array($ref[$segment])) {
                $ref[$segment] = [];
            }
            $ref = &$ref[$segment];
        }
        $ref = $value;
    }

    /** Include the database config file (isolated to avoid leaking local variables). */
    public static function loadDatabase(): array
    {
        return (static function (string $file): array {
            return require $file;
        })(CONFIG_PATH . '/database.php');
    }

    public static function reload(): void
    {
        self::$items = null;
    }
}
