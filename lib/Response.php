<?php
/**
 * HTTP response helpers (JSON, CSV, PDF-friendly downloads, cache headers).
 */
declare(strict_types=1);

namespace Saee\Lib;

final class Response
{
    public static function json(mixed $data, int $status = 200, array $headers = []): never
    {
        if (!headers_sent()) {
            http_response_code($status);
            header('Content-Type: application/json; charset=utf-8');
            header('X-Content-Type-Options: nosniff');
            foreach ($headers as $k => $v) {
                header($k . ': ' . $v);
            }
        }
        echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        exit;
    }

    public static function success(string $message = 'OK', array $data = [], int $status = 200): never
    {
        self::json(['success' => true, 'message' => $message, 'data' => $data], $status);
    }

    public static function error(string $message, array $errors = [], int $status = 422): never
    {
        self::json(['success' => false, 'message' => $message, 'errors' => $errors], $status);
    }

    public static function csv(string $filename, array $rows, array $headers = []): never
    {
        if (!headers_sent()) {
            header('Content-Type: text/csv; charset=utf-8');
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Pragma: no-cache');
            header('Expires: 0');
        }
        $out = fopen('php://output', 'w');
        // BOM keeps Excel happy with UTF-8
        fwrite($out, "\xEF\xBB\xBF");
        if ($headers !== []) {
            fputcsv($out, $headers);
        }
        foreach ($rows as $row) {
            fputcsv($out, array_map([self::class, 'csvCell'], $row));
        }
        fclose($out);
        exit;
    }

    private static function csvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');
        // Prevent CSV formula injection.
        if ($value !== '' && in_array($value[0], ['=', '+', '-', '@', "\t", "\r"], true)) {
            $value = "'" . $value;
        }
        return $value;
    }

    public static function download(string $filename, string $content, string $contentType = 'application/octet-stream'): never
    {
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: attachment; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Content-Length: ' . strlen($content));
        }
        echo $content;
        exit;
    }

    public static function file(string $absolutePath, string $filename = '', string $contentType = ''): never
    {
        if (!is_file($absolutePath)) {
            http_response_code(404);
            exit('File not found');
        }
        $filename = $filename ?: basename($absolutePath);
        $contentType = $contentType ?: (mime_content_type($absolutePath) ?: 'application/octet-stream');
        if (!headers_sent()) {
            header('Content-Type: ' . $contentType);
            header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
            header('Content-Length: ' . filesize($absolutePath));
            header('X-Content-Type-Options: nosniff');
        }
        readfile($absolutePath);
        exit;
    }

    public static function noCache(): void
    {
        if (headers_sent()) {
            return;
        }
        header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
        header('Pragma: no-cache');
        header('Expires: 0');
    }
}
