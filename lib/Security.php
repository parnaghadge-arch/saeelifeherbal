<?php
/**
 * Security primitives: CSRF, XSS escaping, secure tokens, password hashing,
 * login throttling, file-upload validation and encryption for sensitive fields.
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Security
{
    // ---------------------------------------------------------------- output
    public static function escape(mixed $value): string
    {
        return htmlspecialchars((string) ($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Sanitize rich text coming from the WYSIWYG editor. Removes scripts, event handlers,
     * javascript: URLs and other active content while allowing formatting tags.
     */
    public static function cleanHtml(?string $html): string
    {
        if ($html === null || $html === '') {
            return '';
        }
        $allowed = '<p><br><b><strong><i><em><u><s><strike><sub><sup><h1><h2><h3><h4><h5><h6><ul><ol><li><blockquote><a><img><table><thead><tbody><tfoot><tr><th><td><hr><span><div><figure><figcaption><iframe><video><source><pre><code>';
        $clean = strip_tags($html, $allowed);
        // Remove on* attributes and javascript:/data:text/html URLs.
        $clean = preg_replace('/\son[a-z]+\s*=\s*("[^"]*"|\'[^\']*\'|[^\s>]+)/i', '', $clean) ?? '';
        $clean = preg_replace('/(href|src)\s*=\s*("|\')\s*(javascript|vbscript|data:text\/html)[^"\']*(\2)/i', '$1="#"', $clean) ?? '';
        $clean = preg_replace('/<(script|style|form|input|button|object|embed|meta|link|base)\b[^>]*>.*?<\/\1>/is', '', $clean) ?? '';
        return $clean;
    }

    public static function e2(mixed $value): string
    {
        return self::escape($value);
    }

    // ------------------------------------------------------------------ CSRF
    public static function csrfToken(): string
    {
        Session::start();
        if (empty($_SESSION['_csrf_token'])) {
            $_SESSION['_csrf_token'] = bin2hex(random_bytes(32));
            $_SESSION['_csrf_time'] = time();
        }
        return $_SESSION['_csrf_token'];
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="_csrf" value="' . self::escape(self::csrfToken()) . '">';
    }

    public static function verifyCsrf(?string $token = null, bool $throw = true): bool
    {
        Session::start();
        $token ??= $_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? null;
        $sessionToken = $_SESSION['_csrf_token'] ?? '';
        $valid = is_string($token) && $sessionToken !== '' && hash_equals($sessionToken, $token);
        if (!$valid && $throw) {
            http_response_code(419);
            if (self::wantsJson()) {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'Security token expired. Please refresh and try again.']);
            } else {
                echo '<h1>419 - Security token expired</h1><p>Please refresh the page and try again.</p>';
            }
            exit;
        }
        return $valid;
    }

    public static function wantsJson(): bool
    {
        $accept = $_SERVER['HTTP_ACCEPT'] ?? '';
        $requestedWith = $_SERVER['HTTP_X_REQUESTED_WITH'] ?? '';
        $uri = $_SERVER['REQUEST_URI'] ?? '';
        return str_contains($accept, 'application/json')
            || strtolower($requestedWith) === 'xmlhttprequest'
            || str_contains($uri, '/api/')
            || !empty($_POST['_ajax']);
    }

    // --------------------------------------------------------------- tokens
    public static function randomToken(int $bytes = 32): string
    {
        return bin2hex(random_bytes($bytes));
    }

    public static function hashToken(string $token): string
    {
        return hash('sha256', $token);
    }

    // -------------------------------------------------------------- passwords
    /** Alias kept for readability at call sites (same as hashPassword). */
    public static function passwordHash(string $password): string
    {
        return self::hashPassword($password);
    }

    public static function hashPassword(string $password): string
    {
        return password_hash($password, PASSWORD_DEFAULT);
    }

    public static function verifyPassword(string $password, string $hash): bool
    {
        return password_verify($password, $hash);
    }

    public static function passwordIssues(string $password): array
    {
        $issues = [];
        if (strlen($password) < PASSWORD_MIN_LENGTH) {
            $issues[] = 'Password must be at least ' . PASSWORD_MIN_LENGTH . ' characters.';
        }
        if (!preg_match('/[A-Za-z]/', $password)) {
            $issues[] = 'Password must contain at least one letter.';
        }
        if (!preg_match('/\d/', $password)) {
            $issues[] = 'Password must contain at least one number.';
        }
        return $issues;
    }

    // ------------------------------------------------------------- encryption
    /**
     * Application-level encryption for sensitive data at rest (KYC numbers, bank accounts).
     * Uses config security.encryption_key. Falls back to a derived key when not configured.
     */
    private static function key(): string
    {
        $raw = Config::get('security.encryption_key') ?: (getenv('SAEE_ENCRYPTION_KEY') ?: 'saee-life-herbal-default-key-rotate-me');
        return hash('sha256', (string) $raw, true);
    }

    public static function encrypt(?string $plain): ?string
    {
        if ($plain === null || $plain === '') {
            return $plain;
        }
        if (str_starts_with($plain, 'enc:v1:')) {
            return $plain;
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return $plain;
        }
        return 'enc:v1:' . base64_encode($iv . $tag . $cipher);
    }

    public static function decrypt(?string $value): ?string
    {
        if ($value === null || $value === '' || !str_starts_with($value, 'enc:v1:')) {
            return $value;
        }
        $payload = base64_decode(substr($value, 7), true);
        if ($payload === false || strlen($payload) < 29) {
            return '';
        }
        $iv = substr($payload, 0, 12);
        $tag = substr($payload, 12, 16);
        $cipher = substr($payload, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', self::key(), OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? '' : $plain;
    }

    public static function maskAccount(?string $number, int $visible = 4): string
    {
        $number = trim((string) $number);
        if ($number === '') {
            return '';
        }
        $len = strlen($number);
        if ($len <= $visible) {
            return str_repeat('*', $len);
        }
        return str_repeat('*', $len - $visible) . substr($number, -$visible);
    }

    public static function maskPan(?string $pan): string
    {
        $pan = strtoupper(trim((string) $pan));
        if ($pan === '') {
            return '';
        }
        if (strlen($pan) < 6) {
            return str_repeat('*', strlen($pan));
        }
        return substr($pan, 0, 2) . str_repeat('*', strlen($pan) - 4) . substr($pan, -2);
    }

    public static function maskAadhaar(?string $aadhaar): string
    {
        $digits = preg_replace('/\D/', '', (string) $aadhaar);
        if ($digits === '') {
            return '';
        }
        return str_repeat('*', max(0, strlen($digits) - 4)) . substr($digits, -4);
    }

    // ------------------------------------------------------- login throttling
    /**
     * Returns [allowed(bool), remaining_attempts(int), retry_after_seconds(int)]
     */
    public static function throttleStatus(string $scope, string $identifier): array
    {
        $db = Database::instance();
        $row = $db->first(
            'SELECT * FROM login_attempts WHERE scope = :s AND identifier = :i',
            ['s' => $scope, 'i' => $identifier]
        );
        if (!$row) {
            return [true, LOGIN_MAX_ATTEMPTS, 0];
        }
        $lockedUntil = $row['locked_until'] ? strtotime((string) $row['locked_until']) : null;
        if ($lockedUntil && $lockedUntil > time()) {
            return [false, 0, $lockedUntil - time()];
        }
        return [true, max(0, LOGIN_MAX_ATTEMPTS - (int) $row['attempts']), 0];
    }

    public static function recordFailure(string $scope, string $identifier): void
    {
        $db = Database::instance();
        $row = $db->first('SELECT * FROM login_attempts WHERE scope = :s AND identifier = :i', ['s' => $scope, 'i' => $identifier]);
        $now = date('Y-m-d H:i:s');
        if (!$row) {
            $db->insert('login_attempts', [
                'scope' => $scope, 'identifier' => $identifier, 'attempts' => 1,
                'last_attempt_at' => $now, 'created_at' => $now,
            ]);
            return;
        }
        $attempts = (int) $row['attempts'] + 1;
        $data = ['attempts' => $attempts, 'last_attempt_at' => $now];
        if ($attempts >= LOGIN_MAX_ATTEMPTS) {
            $data['locked_until'] = date('Y-m-d H:i:s', time() + LOGIN_LOCK_MINUTES * 60);
            $data['attempts'] = 0;
        }
        $db->update('login_attempts', $data, 'id = :id', ['id' => $row['id']]);
    }

    public static function clearFailures(string $scope, string $identifier): void
    {
        Database::instance()->delete('login_attempts', 'scope = :s AND identifier = :i', ['s' => $scope, 'i' => $identifier]);
    }

    // ------------------------------------------------------------- uploads
    public static function uploadError(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => 'The file is larger than the allowed size.',
            UPLOAD_ERR_PARTIAL => 'The file was only partially uploaded. Please retry.',
            UPLOAD_ERR_NO_FILE => 'No file was uploaded.',
            UPLOAD_ERR_NO_TMP_DIR => 'Server upload directory is missing.',
            UPLOAD_ERR_CANT_WRITE => 'Server could not write the uploaded file.',
            UPLOAD_ERR_EXTENSION => 'Upload blocked by a server extension.',
            default => 'Unknown upload error.',
        };
    }

    public static function initSessionSecurity(): void
    {
        if (session_status() === PHP_SESSION_ACTIVE) {
            return;
        }
        $secure = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
            || strtolower($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https';
        session_name(SESSION_NAME);
        session_set_cookie_params([
            'lifetime' => 0,
            'path' => '/',
            'secure' => $secure,
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        @session_start();
    }
}
