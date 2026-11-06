<?php
/** Simple login/activity bookkeeping for analytics. */
declare(strict_types=1);

namespace Saee\Lib;

final class LoginActivity
{
    public static function record(string $actorType, int $actorId): void
    {
        try {
            Database::instance()->insert('page_views', [
                'page_url' => '/login/' . $actorType,
                'visitor_hash' => hash('sha256', $actorType . ':' . $actorId),
                'ip_address' => Request::ip(),
                'user_agent' => Request::userAgent(),
                'referrer' => null,
                'created_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable) {
            // analytics must never break authentication
        }
    }
}
