<?php
/**
 * Secure document viewer for KYC and bank files stored outside the web root.
 * Files saved by Uploader with $public = false live in storage/private and are
 * referenced as "private:<relative path>"; they can only be streamed through
 * this endpoint after an authenticated admin permission check.
 */
declare(strict_types=1);
require __DIR__ . '/includes/_guard.php';

use Saee\Lib\Audit;
use Saee\Lib\Request;
use Saee\Lib\Session;

$type = (string) Request::query('type', '');
$id = Request::int('id');
$field = (string) Request::query('field', '');

/**
 * Allowed field => [permission, table, label] pairs. Anything outside this map is refused,
 * so the endpoint can never be used to read arbitrary columns or paths.
 */
$allowed = [
    'kyc' => [
        'table' => 'member_kyc',
        'permission' => 'kyc.view',
        'fields' => [
            'pan_document' => 'PAN document',
            'identity_document' => 'Aadhaar / ID document',
            'address_document' => 'Address proof',
            'photo' => 'Photograph',
        ],
    ],
    'bank' => [
        'table' => 'member_bank_accounts',
        'permission' => 'bank.view',
        'fields' => [
            'cheque_image' => 'Cancelled cheque',
        ],
    ],
];

if (!isset($allowed[$type])) {
    http_response_code(400);
    exit('Unknown document type.');
}
$config = $allowed[$type];
admin_require($config['permission']);

if (!isset($config['fields'][$field])) {
    http_response_code(400);
    exit('Unknown document field.');
}
if ($id <= 0) {
    http_response_code(400);
    exit('Missing document id.');
}

$row = db()->first('SELECT * FROM ' . $config['table'] . ' WHERE id = :id', ['id' => $id]);
if (!$row) {
    http_response_code(404);
    exit('Document record not found.');
}

$stored = (string) ($row[$field] ?? '');
if ($stored === '') {
    http_response_code(404);
    exit('No file has been uploaded for this field.');
}

$relative = str_starts_with($stored, 'private:') ? substr($stored, 8) : $stored;
$base = str_starts_with($stored, 'private:')
    ? STORAGE_PATH . DS . 'private'
    : UPLOAD_PATH;
$absolute = realpath($base . DS . str_replace(['/', '\\'], DS, ltrim($relative, '/\\')));
$baseReal = realpath($base);
if ($absolute === false || $baseReal === false || !str_starts_with($absolute, $baseReal . DS) || !is_file($absolute)) {
    http_response_code(404);
    exit('The stored file is missing.');
}

$mime = 'application/octet-stream';
if (function_exists('mime_content_type')) {
    $detected = @mime_content_type($absolute);
    if (is_string($detected) && $detected !== '') {
        $mime = $detected;
    }
}
// Only ever render safe types inline; everything else downloads.
$inline = in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif', 'application/pdf'], true);
$filename = ($config['fields'][$field] . '-' . $row['id'] . '.' . pathinfo($absolute, PATHINFO_EXTENSION));

Audit::log('document_view', $type === 'kyc' ? 'kyc' : 'bank', 'Viewed ' . $config['fields'][$field] . ' for record #' . $id, $config['table'], $id);

while (ob_get_level() > 0) {
    ob_end_clean();
}
header('Content-Type: ' . $mime);
header('Content-Length: ' . (string) filesize($absolute));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '_', $filename) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');
header('X-Robots-Tag: noindex, nofollow');
readfile($absolute);
exit;
