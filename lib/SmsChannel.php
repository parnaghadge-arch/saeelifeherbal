<?php
/**
 * SMS notification channel - pluggable gateway.
 * Ships with a logging gateway; configure a provider by extending this class.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class SmsChannel implements NotificationChannel
{
    public function key(): string
    {
        return 'sms';
    }

    public function enabled(): bool
    {
        return (bool) Settings::get('notify_sms_enabled', false);
    }

    public function send(array $recipient, string $subject, string $body, array $context = []): bool
    {
        $mobile = (string) ($recipient['mobile'] ?? '');
        if ($mobile === '') {
            return false;
        }
        $entry = sprintf("[%s] SMS to %s: %s - %s\n", date('c'), $mobile, $subject, $body);
        @file_put_contents(LOG_PATH . DIRECTORY_SEPARATOR . 'sms.log', $entry, FILE_APPEND);
        return true;
    }
}
