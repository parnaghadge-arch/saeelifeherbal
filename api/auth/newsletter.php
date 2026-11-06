<?php
declare(strict_types=1);
require_once dirname(__DIR__) . '/_bootstrap.php';

use Saee\Lib\Database;
use Saee\Lib\Request;
use Saee\Lib\Response;
use Saee\Lib\Security;

api_require_post();
$email = strtolower(trim((string) Request::input('email', '')));
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    Response::error('Please enter a valid email address.', ['email' => ['Invalid email.']]);
}
$db = Database::instance();
if ($db->exists('newsletter_subscribers', 'email = :e', ['e' => $email])) {
    Response::success('You are already subscribed. Thank you!');
}
$db->insert('newsletter_subscribers', [
    'email' => $email,
    'status' => 1,
    'token' => Security::randomToken(16),
    'created_at' => date('Y-m-d H:i:s'),
    'updated_at' => date('Y-m-d H:i:s'),
]);
Response::success('Subscribed! You will receive agri updates from Saee Life Herbal.');
