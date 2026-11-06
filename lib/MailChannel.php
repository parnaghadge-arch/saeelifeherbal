<?php
/**
 * Email notification channel (PHP mail / SMTP relay friendly).
 * When transport is "log" (default in development) messages are written to storage/logs/mail.log.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class MailChannel implements NotificationChannel
{
    public function __construct(private array $config)
    {
    }

    public function key(): string
    {
        return 'email';
    }

    public function enabled(): bool
    {
        return (bool) Settings::get('notify_email_enabled', false);
    }

    public function send(array $recipient, string $subject, string $body, array $context = []): bool
    {
        $to = (string) ($recipient['email'] ?? '');
        if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
            return false;
        }
        $from = (string) ($this->config['from'] ?? 'no-reply@saeelifeherbal.com');
        $fromName = (string) ($this->config['from_name'] ?? APP_NAME);
        $html = $this->wrap($subject, $body);
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: ' . $this->encodeHeader($fromName) . ' <' . $from . '>',
            'Reply-To: ' . $from,
            'X-Mailer: SaeeLifeHerbal/' . APP_VERSION,
        ];

        if (($this->config['transport'] ?? 'log') === 'smtp' && ($this->config['host'] ?? '') !== '') {
            return $this->smtpSend($to, $subject, $html, $headers);
        }

        $logFile = LOG_PATH . DIRECTORY_SEPARATOR . 'mail.log';
        $entry = sprintf("[%s]\nTo: %s\nSubject: %s\n\n%s\n%s\n\n", date('c'), $to, $subject, implode("\n", $headers), $html);
        @file_put_contents($logFile, $entry, FILE_APPEND);
        $sent = @mail($to, $this->encodeHeader($subject), $html, implode("\r\n", $headers));
        return (bool) $sent || is_file($logFile);
    }

    private function smtpSend(string $to, string $subject, string $html, array $headers): bool
    {
        $host = (string) $this->config['host'];
        $port = (int) ($this->config['port'] ?? 587);
        $encryption = (string) ($this->config['encryption'] ?? 'tls');
        $username = (string) ($this->config['username'] ?? '');
        $password = (string) ($this->config['password'] ?? '');

        $remote = ($encryption === 'ssl' ? 'ssl://' : '') . $host . ':' . $port;
        $socket = @stream_socket_client($remote, $errno, $errstr, 15);
        if (!$socket) {
            error_log('SMTP connect failed: ' . $errstr);
            return false;
        }
        $read = static fn () => fgets($socket, 515) ?: '';
        $write = static function (string $line) use ($socket) { fwrite($socket, $line . "\r\n"); };
        $read();
        $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $read();
        if ($encryption === 'tls') {
            $write('STARTTLS');
            $read();
            stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT);
            $write('EHLO ' . ($_SERVER['HTTP_HOST'] ?? 'localhost'));
            $read();
        }
        if ($username !== '') {
            $write('AUTH LOGIN');
            $read();
            $write(base64_encode($username));
            $read();
            $write(base64_encode($password));
            $read();
        }
        $from = (string) ($this->config['from'] ?? 'no-reply@saeelifeherbal.com');
        $write('MAIL FROM: <' . $from . '>');
        $read();
        $write('RCPT TO: <' . $to . '>');
        $read();
        $write('DATA');
        $read();
        $write('Subject: ' . $this->encodeHeader($subject));
        foreach ($headers as $h) {
            $write($h);
        }
        $write('');
        foreach (preg_split('/\r\n|\n/', $html) ?: [] as $line) {
            $write($line);
        }
        $write('.');
        $write('QUIT');
        fclose($socket);
        return true;
    }

    private function encodeHeader(string $value): string
    {
        return '=?UTF-8?B?' . base64_encode($value) . '?=';
    }

    private function wrap(string $title, string $body): string
    {
        $siteName = e((string) Settings::get('site_name', APP_NAME));
        $primary = e((string) Settings::get('theme_primary', '#247A3C'));
        $bodyHtml = nl2br(e($body));
        return <<<HTML
<!doctype html><html><body style="margin:0;background:#F7FAF6;font-family:Arial,Helvetica,sans-serif;color:#263238">
<div style="max-width:600px;margin:0 auto;padding:24px">
  <div style="background:$primary;color:#fff;padding:18px 22px;border-radius:12px 12px 0 0;font-size:18px;font-weight:bold">$siteName</div>
  <div style="background:#fff;padding:22px;border-radius:0 0 12px 12px;border:1px solid #E8F5E9">
    <h2 style="color:$primary;margin:0 0 12px;font-size:20px">$title</h2>
    <div style="font-size:15px;line-height:1.6">$bodyHtml</div>
    <p style="margin-top:24px;font-size:12px;color:#607D8B">This is an automated message. Please do not reply directly to this email.</p>
  </div>
</div></body></html>
HTML;
    }
}
