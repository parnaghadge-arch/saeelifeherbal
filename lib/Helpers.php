<?php
/**
 * Global helper functions shared by the website, admin panel, member portal and APIs.
 */
declare(strict_types=1);

use Saee\Lib\Settings;
use Saee\Lib\Security;
use Saee\Lib\Url;

if (!function_exists('e')) {
    /** Escape output (XSS prevention). */
    function e(mixed $value): string
    {
        return Security::escape($value);
    }
}

if (!function_exists('setting')) {
    function setting(string $key, mixed $default = null): mixed
    {
        return Settings::get($key, $default);
    }
}

if (!function_exists('currency_symbol')) {
    /**
     * The currency symbol to print before an amount.
     *
     * CURRENCY_SYMBOL is a literal '₹' in config/app.php. When that file is transferred with
     * the wrong encoding (or saved as ANSI by an editor, or served with a non-UTF-8 default
     * charset) those three UTF-8 bytes come back as stray digits — which is how a price of
     * 940.00 ends up on screen as "262145940.00".
     *
     * The constant is only trusted when it is exactly the rupee sign or the ASCII "Rs."/"Rs"
     * fallback. Anything else that contains ASCII digits or control characters is treated as
     * corrupt and replaced, so a mangled config file can never leak digits into a price
     * anywhere on the site. A genuine symbol for another currency (e.g. '$') is left alone.
     */
    function currency_symbol(): string
    {
        $raw = defined('CURRENCY_SYMBOL') ? (string) CURRENCY_SYMBOL : '';
        if ($raw === "\u{20B9}" || $raw === 'Rs.' || $raw === 'Rs') {
            return $raw;
        }
        if ($raw === '' || preg_match('//u', $raw) !== 1) {
            return "\u{20B9}"; // empty, or not even valid UTF-8
        }
        // A real currency symbol is one or two printable (non-alphanumeric) characters such as
        // ₹, $, £, € or "Rs.". Digits, control characters or the U+FFFD replacement character
        // all mean the bytes were damaged in transit, so fall back to the rupee sign.
        if (function_exists('mb_strlen') ? mb_strlen($raw, 'UTF-8') > 3 : strlen($raw) > 6) {
            return "\u{20B9}";
        }
        if (preg_match('/[0-9\x00-\x1F\x7F\x{FFFD}]/u', $raw)) {
            return "\u{20B9}";
        }
        return $raw;
    }
}

if (!function_exists('money')) {
    /** Format an amount in Indian numbering with the configured currency symbol. */
    function money(float|int|string|null $amount, bool $withSymbol = true, int $decimals = 2): string
    {
        $amount = (float) ($amount ?? 0);
        $formatted = number_format($amount, $decimals, '.', ',');
        return $withSymbol ? currency_symbol() . $formatted : $formatted;
    }
}

if (!function_exists('money_in')) {
    /** Format an amount using the Indian lakh/crore grouping. */
    function money_in(float|int|string|null $amount, bool $withSymbol = true): string
    {
        $amount = (float) ($amount ?? 0);
        $negative = $amount < 0;
        $amount = abs($amount);
        $int = floor($amount);
        $decimals = (int) round(($amount - $int) * 100);
        $str = (string) (int) $int;
        $last3 = substr($str, -3);
        $rest = substr($str, 0, -3);
        if ($rest !== '') {
            $rest = preg_replace('/\B(?=(\d{2})+(?!\d))/', ',', $rest);
            $str = $rest . ',' . $last3;
        }
        $out = $str . '.' . str_pad((string) $decimals, 2, '0', STR_PAD_LEFT);
        return ($negative ? '-' : '') . ($withSymbol ? currency_symbol() : '') . $out;
    }
}

if (!function_exists('mask_account')) {
    function mask_account(?string $number): string
    {
        return Security::maskAccount($number);
    }
}

if (!function_exists('format_date')) {
    function format_date(?string $date, string $format = 'd M Y'): string
    {
        if (!$date) {
            return '—';
        }
        $ts = strtotime($date);
        return $ts ? date($format, $ts) : '—';
    }
}

if (!function_exists('format_datetime')) {
    function format_datetime(?string $date): string
    {
        return format_date($date, 'd M Y, h:i A');
    }
}

if (!function_exists('time_ago')) {
    function time_ago(?string $date): string
    {
        if (!$date) {
            return '—';
        }
        $ts = strtotime($date);
        if (!$ts) {
            return '—';
        }
        $diff = time() - $ts;
        if ($diff < 60) {
            return 'just now';
        }
        $units = [31536000 => 'year', 2592000 => 'month', 604800 => 'week', 86400 => 'day', 3600 => 'hour', 60 => 'minute'];
        foreach ($units as $seconds => $label) {
            if ($diff >= $seconds) {
                $value = (int) floor($diff / $seconds);
                return $value . ' ' . $label . ($value > 1 ? 's' : '') . ' ago';
            }
        }
        return 'just now';
    }
}

if (!function_exists('slugify')) {
    function slugify(string $text, string $separator = '-'): string
    {
        $text = trim($text);
        if (function_exists('iconv')) {
            $converted = @iconv('UTF-8', 'ASCII//TRANSLIT', $text);
            if ($converted !== false) {
                $text = $converted;
            }
        }
        $text = strtolower($text);
        $text = preg_replace('/[^a-z0-9]+/', $separator, $text) ?? '';
        return trim($text, $separator) ?: 'section';
    }
}

if (!function_exists('unique_slug')) {
    /**
     * Produce a slug that is unique within a table/column.
     */
    function unique_slug(string $table, string $column, string $base, ?int $ignoreId = null): string
    {
        $db = \Saee\Lib\Database::instance();
        $slug = slugify($base);
        $candidate = $slug;
        $i = 2;
        while (true) {
            $sql = "SELECT COUNT(*) FROM {$table} WHERE {$column} = :slug" . ($ignoreId ? ' AND id <> :id' : '');
            $params = ['slug' => $candidate];
            if ($ignoreId) {
                $params['id'] = $ignoreId;
            }
            if ((int) $db->value($sql, $params) === 0) {
                return $candidate;
            }
            $candidate = $slug . '-' . $i++;
        }
    }
}

if (!function_exists('status_badge')) {
    function status_badge(?string $status): string
    {
        $map = [
            'active' => 'success', 'approved' => 'success', 'paid' => 'success', 'available' => 'success',
            'delivered' => 'success', 'published' => 'success', 'completed' => 'success', 'verified' => 'success',
            'pending' => 'warning', 'payment_pending' => 'warning', 'submitted' => 'warning', 'under_verification' => 'info',
            'processing' => 'info', 'shipped' => 'primary', 'read' => 'info', 'replied' => 'success',
            'rejected' => 'danger', 'cancelled' => 'danger', 'failed' => 'danger', 'suspended' => 'danger',
            'reversed' => 'dark', 'refunded' => 'secondary', 'inactive' => 'secondary', 'draft' => 'secondary',
            'not_submitted' => 'secondary', 'new' => 'primary', 'spam' => 'dark', 'resubmission_required' => 'warning',
            // B2B / CRM pipeline stages
            'contacted' => 'info', 'qualified' => 'primary', 'proposal' => 'warning',
            'negotiation' => 'warning', 'won' => 'success', 'lost' => 'danger',
        ];
        $label = ucwords(str_replace('_', ' ', (string) ($status ?: 'unknown')));
        // Payouts read better as "In Progress" than the raw enum word "Processing".
        if (($status ?? '') === 'processing') {
            $label = 'In Progress';
        }
        $class = $map[$status ?? ''] ?? 'secondary';
        return '<span class="badge badge-status bg-' . $class . '-subtle text-' . ($class === 'dark' ? 'light' : $class) . ' border border-' . ($class === 'dark' ? 'dark' : $class) . '-subtle">' . e($label) . '</span>';
    }
}

if (!function_exists('product_url')) {
    /** Public (extension-less) URL of a product: /product/<slug>. */
    function product_url(string $slug): string
    {
        return \Saee\Lib\Url::to('product/' . rawurlencode($slug));
    }
}

if (!function_exists('blog_url')) {
    /** Public (extension-less) URL of a blog post: /blog/<slug>. */
    function blog_url(string $slug): string
    {
        return \Saee\Lib\Url::to('blog/' . rawurlencode($slug));
    }
}

if (!function_exists('active_class')) {
    /**
     * Mark a nav link as active. The current route is normalised (sub-directory base removed,
     * ".php" and trailing slashes dropped) so "/products.php" and "/products" both match
     * "products", and the home link matches both "/" and "/index.php".
     */
    function active_class(string $path, string $class = 'active'): string
    {
        $current = function_exists('saee_request_path')
            ? saee_request_path()
            : trim(\Saee\Lib\Request::uri(), '/');
        if ($current === 'index.php' || $current === 'index') {
            $current = '';
        }
        if (str_ends_with($current, '.php')) {
            $current = substr($current, 0, -4);
        }
        $path = trim($path, '/');
        if (str_ends_with($path, '.php')) {
            $path = substr($path, 0, -4);
        }
        if ($path === 'home') {
            $path = '';
        }
        if ($path === '') {
            return $current === '' ? $class : '';
        }
        return ($current === $path || str_starts_with($current, $path . '/')) ? $class : '';
    }
}

if (!function_exists('initials')) {
    function initials(string $name): string
    {
        $parts = preg_split('/\s+/', trim($name)) ?: [];
        $letters = '';
        foreach (array_slice($parts, 0, 2) as $part) {
            $letters .= mb_strtoupper(mb_substr($part, 0, 1));
        }
        return $letters ?: 'S';
    }
}

if (!function_exists('truncate_text')) {
    function truncate_text(?string $text, int $length = 140): string
    {
        $text = trim(strip_tags((string) $text));
        if (mb_strlen($text) <= $length) {
            return $text;
        }
        return rtrim(mb_substr($text, 0, $length)) . '…';
    }
}

if (!function_exists('amount_in_words')) {
    /** Indian rupee amount in words (used on invoices). */
    function amount_in_words(float $amount): string
    {
        $amount = round($amount, 2);
        $rupees = (int) floor($amount);
        $paise = (int) round(($amount - $rupees) * 100);
        $words = ucwords(trim(indian_number_words($rupees))) . ' Rupees';
        if ($paise > 0) {
            $words .= ' and ' . ucwords(indian_number_words($paise)) . ' Paise';
        }
        return $words . ' Only';
    }

    function indian_number_words(int $number): string
    {
        $ones = ['', 'one', 'two', 'three', 'four', 'five', 'six', 'seven', 'eight', 'nine', 'ten', 'eleven', 'twelve', 'thirteen', 'fourteen', 'fifteen', 'sixteen', 'seventeen', 'eighteen', 'nineteen'];
        $tens = ['', '', 'twenty', 'thirty', 'forty', 'fifty', 'sixty', 'seventy', 'eighty', 'ninety'];
        $say = static function (int $n) use (&$say, $ones, $tens): string {
            if ($n < 20) {
                return $ones[$n];
            }
            if ($n < 100) {
                return trim($tens[intdiv($n, 10)] . ' ' . $ones[$n % 10]);
            }
            if ($n < 1000) {
                return trim($ones[intdiv($n, 100)] . ' hundred ' . $say($n % 100));
            }
            if ($n < 100000) {
                return trim($say(intdiv($n, 1000)) . ' thousand ' . $say($n % 1000));
            }
            if ($n < 10000000) {
                return trim($say(intdiv($n, 100000)) . ' lakh ' . $say($n % 100000));
            }
            return trim($say(intdiv($n, 10000000)) . ' crore ' . $say($n % 10000000));
        };
        return $number === 0 ? 'zero' : trim($say($number));
    }
}

if (!function_exists('csrf_field')) {
    function csrf_field(): string
    {
        return Security::csrfField();
    }
}

if (!function_exists('csrf_token_value')) {
    function csrf_token_value(): string
    {
        return Security::csrfToken();
    }
}

if (!function_exists('old_input')) {
    function old_input(string $key, mixed $default = ''): string
    {
        $old = \Saee\Lib\Session::get('_old_input', []);
        $value = $old[$key] ?? $default;
        return e(is_array($value) ? '' : $value);
    }
}

if (!function_exists('asset_url')) {
    function asset_url(string $path): string
    {
        return Url::asset($path);
    }
}

if (!function_exists('upload_url')) {
    function upload_url(?string $path): string
    {
        return Url::upload((string) $path);
    }
}

if (!function_exists('is_post')) {
    function is_post(): bool
    {
        return \Saee\Lib\Request::isPost();
    }
}

if (!function_exists('array_get_val')) {
    function array_get_val(array $array, string $key, mixed $default = null): mixed
    {
        return $array[$key] ?? $default;
    }
}

if (!function_exists('pct')) {
    function pct(float|int $value, float|int $total): string
    {
        if ((float) $total === 0.0) {
            return '0%';
        }
        return round(((float) $value / (float) $total) * 100, 1) . '%';
    }
}

if (!function_exists('csv_export_row')) {
    function csv_export_row(array $row): array
    {
        return array_map(static fn ($v) => is_array($v) ? json_encode($v) : $v, $row);
    }
}

if (!function_exists('client_ip')) {
    function client_ip(): string
    {
        return \Saee\Lib\Request::ip();
    }
}

if (!function_exists('random_code')) {
    function random_code(int $length = 6): string
    {
        return strtoupper(substr(bin2hex(random_bytes($length)), 0, $length));
    }
}
