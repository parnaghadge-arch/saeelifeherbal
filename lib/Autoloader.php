<?php
/**
 * Lightweight PSR-4 style autoloader for the /lib directory.
 */
spl_autoload_register(static function (string $class): void {
    $map = [
        'Saee\\Lib\\' => LIB_PATH . DS,
    ];
    foreach ($map as $prefix => $dir) {
        if (str_starts_with($class, $prefix)) {
            $relative = str_replace('\\', DS, substr($class, strlen($prefix)));
            $file = $dir . $relative . '.php';
            if (is_file($file)) {
                require_once $file;
                return;
            }
        }
    }
});
