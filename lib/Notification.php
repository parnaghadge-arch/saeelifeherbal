<?php
/**
 * Notification service with a channel-agnostic architecture.
 *
 * In-system notifications are stored in the notifications table. Email/SMS/WhatsApp
 * gateways implement NotificationChannel and are registered here, so adding a gateway
 * later never touches business code.
 */
declare(strict_types=1);

namespace Saee\Lib;

interface NotificationChannel
{
    public function key(): string;
    public function enabled(): bool;
    /** @return bool true when delivered */
    public function send(array $recipient, string $subject, string $body, array $context = []): bool;
}

final class Notification
{
    /** @var array<string,NotificationChannel> */
    private static array $channels = [];

    public static function registerChannel(NotificationChannel $channel): void
    {
        self::$channels[$channel->key()] = $channel;
    }

    /**
     * Send a notification to a member, customer or admin.
     */
    public static function send(
        string $recipientType,
        int $recipientId,
        string $templateCode,
        string $title,
        string $message,
        array $variables = [],
        array $options = []
    ): void {
        try {
            $db = Database::instance();
            $link = $options['link'] ?? null;
            $db->insert('notifications', [
                'recipient_type' => $recipientType,
                'recipient_id' => $recipientId,
                'template_code' => $templateCode,
                'title' => mb_substr($title, 0, 190),
                'message' => $message,
                'type' => $options['type'] ?? 'info',
                'link' => $link,
                'icon' => $options['icon'] ?? 'fa-bell',
                'channel' => 'in_system',
                'is_read' => 0,
                'sent_at' => date('Y-m-d H:i:s'),
                'created_at' => date('Y-m-d H:i:s'),
            ]);

            $recipient = self::resolveRecipient($recipientType, $recipientId);
            if ($recipient !== null) {
                foreach (self::$channels as $channel) {
                    if ($channel->enabled() && in_array($channel->key(), $options['channels'] ?? ['email', 'sms', 'whatsapp'], true)) {
                        $channel->send($recipient, $title, self::render($templateCode, $variables) ?: $message, $variables);
                    }
                }
            }
        } catch (\Throwable $e) {
            error_log('notification failed: ' . $e->getMessage());
        }
    }

    public static function sendToAdmins(string $title, string $message, array $options = []): void
    {
        try {
            $admins = Database::instance()->select("SELECT id FROM admin_users WHERE status = 'active'");
            foreach ($admins as $admin) {
                self::send('admin', (int) $admin['id'], $options['template'] ?? 'admin_alert', $title, $message, [], $options);
            }
        } catch (\Throwable $e) {
            error_log('admin notification failed: ' . $e->getMessage());
        }
    }

    public static function render(string $code, array $variables): string
    {
        $template = Database::instance()->first('SELECT * FROM notification_templates WHERE code = :c AND status = 1', ['c' => $code]);
        if (!$template) {
            return '';
        }
        $body = (string) $template['body'];
        foreach ($variables as $key => $value) {
            $body = str_replace('{{' . $key . '}}', (string) $value, $body);
        }
        return $body;
    }

    private static function resolveRecipient(string $type, int $id): ?array
    {
        $db = Database::instance();
        return match ($type) {
            'member' => $db->first('SELECT name, email, mobile, member_code AS code FROM members WHERE id = :id', ['id' => $id]),
            'customer' => $db->first('SELECT name, email, mobile, id AS code FROM customers WHERE id = :id', ['id' => $id]),
            'admin' => $db->first('SELECT name, email, mobile, username AS code FROM admin_users WHERE id = :id', ['id' => $id]),
            default => null,
        };
    }

    public static function unreadCount(string $type, int $id): int
    {
        try {
            return (int) Database::instance()->value(
                'SELECT COUNT(*) FROM notifications WHERE recipient_type = :t AND recipient_id = :i AND is_read = 0',
                ['t' => $type, 'i' => $id]
            );
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function markRead(string $type, int $id, ?int $notificationId = null): void
    {
        $db = Database::instance();
        if ($notificationId !== null) {
            $db->execute(
                'UPDATE notifications SET is_read = 1, read_at = :now WHERE id = :id AND recipient_type = :t AND recipient_id = :i',
                ['now' => date('Y-m-d H:i:s'), 'id' => $notificationId, 't' => $type, 'i' => $id]
            );
            return;
        }
        $db->execute(
            'UPDATE notifications SET is_read = 1, read_at = :now WHERE recipient_type = :t AND recipient_id = :i AND is_read = 0',
            ['now' => date('Y-m-d H:i:s'), 't' => $type, 'i' => $id]
        );
    }

    public static function history(string $type, int $id, int $limit = 30): array
    {
        return Database::instance()->select(
            'SELECT * FROM notifications WHERE recipient_type = :t AND recipient_id = :i ORDER BY id DESC LIMIT ' . (int) $limit,
            ['t' => $type, 'i' => $id]
        );
    }
}
