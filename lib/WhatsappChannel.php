<?php
/**
 * WhatsApp notification channel - pluggable gateway (logs until a provider is configured).
 */
declare(strict_types=1);

namespace Saee\Lib;

final class WhatsappChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'whatsapp';
    }

    public function enabled(): bool
    {
        return (bool) Settings::get('notify_whatsapp_enabled', false);
    }

    public function send(array $recipient, string $subject, string $body, array $context = []): bool
    {
        $mobile = (string) ($recipient['mobile'] ?? '');
        if ($mobile === '') {
            return false;
        }
        $entry = sprintf("[%s] WHATSAPP to %s: %s - %s\n", date('c'), $mobile, $subject, $body);
        @file_put_contents(LOG_PATH . DIRECTORY_SEPARATOR . 'whatsapp.log', $entry, FILE_APPEND);
        return true;
    }
}
