<?php
/**
 * Administrative audit trail. Every privileged change is recorded immutably.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Audit
{
    public static function log(
        string $action,
        string $module = 'general',
        string $description = '',
        ?string $entityType = null,
        ?int $entityId = null,
        array $changes = []
    ): void {
        try {
            $admin = AdminAuth::user();
            Database::instance()->insert('admin_activity_logs', [
                'admin_id' => $admin['id'] ?? null,
                'admin_name' => $admin['name'] ?? 'System',
                'action' => $action,
                'module' => $module,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'description' => $description !== '' ? $description : null,
                'changes' => $changes !== [] ? json_encode($changes, JSON_UNESCAPED_UNICODE) : null,
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            error_log('Audit log failed: ' . $e->getMessage());
        }
    }

    /** Compare two record versions and return changed fields only. */
    public static function diff(array $before, array $after, array $ignore = ['updated_at', 'created_at', 'password_hash']): array
    {
        $changes = [];
        foreach ($after as $key => $value) {
            if (in_array($key, $ignore, true)) {
                continue;
            }
            $old = $before[$key] ?? null;
            if ((string) $old !== (string) $value) {
                $changes[$key] = ['from' => $old, 'to' => $value];
            }
        }
        return $changes;
    }
}
