<?php
/**
 * Admin bootstrap + authentication guard.
 */
declare(strict_types=1);

require_once dirname(__DIR__, 2) . '/includes/bootstrap.php';
require_once INCLUDES_PATH . '/functions.php';

use Saee\Lib\AdminAuth;
use Saee\Lib\Audit;

// Public admin pages bypass the guard.
if (($adminPublic ?? false) === true) {
    return;
}

AdminAuth::requireLogin();
$adminUser = AdminAuth::user();

/**
 * Require a permission, or send the user to the admin dashboard with an error.
 */
function admin_can(string $permission): bool
{
    return AdminAuth::can($permission);
}

function admin_require(string $permission): void
{
    if (!AdminAuth::can($permission)) {
        \Saee\Lib\Session::error('You do not have permission to access that module.');
        \Saee\Lib\Request::redirect('index.php');
    }
}

/** Convenience DELETE-style handler. */
function admin_log(string $action, string $module, string $description, string $entityType = '', ?int $entityId = null, array $changes = []): void
{
    Audit::log($action, $module, $description, $entityType, $entityId, $changes);
}
